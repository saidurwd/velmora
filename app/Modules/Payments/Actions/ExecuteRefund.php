<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\DTOs\RefundRequest;
use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Payments\Services\RefundLedger;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\Log;

/**
 * Sends an approved refund to the gateway, in short steps so no database lock is held while the gateway is called:
 *   1. one process wins "approved → processing" (the others are told it is already under way), so the gateway is asked once;
 *   2. the gateway is asked, with OUR unique refund id (it recognises a repeat by it);
 *   3. its answer is recorded.
 * A gateway that says "accepted" or "processing" is not "done": the refund stays `processing` until the gateway reports it refunded
 * (see PollRefunds). If the gateway cannot be reached the refund stays `processing` with no gateway id and the same call is repeated later.
 */
final class ExecuteRefund
{
    public function __construct(private readonly GatewayRegistry $gateways, private readonly RefundLedger $ledger, private readonly AuditLogger $audit) {}

    /** @throws PaymentException */
    public function handle(Actor $actor, string $refundPublicId): Refund
    {
        $refund = Transactions::run(function () use ($refundPublicId): Refund {
            $refund = Refund::query()->where('public_id', $refundPublicId)->lockForUpdate()->first() ?? throw new PaymentException('refund_not_found');
            if ($refund->method !== RefundMethod::Gateway) {
                throw new PaymentException('refund_wrong_method');
            }
            if ($refund->status !== RefundStatus::Approved) {
                throw new PaymentException($refund->status === RefundStatus::Processing ? 'refund_already_processing' : 'refund_not_approved');
            }

            $refund->forceFill(['status' => RefundStatus::Processing])->save();

            return $refund;
        });
        $this->audit->record('refund.sent', $refund, ['status' => 'approved'], ['status' => 'processing'], $actor);

        return $this->callGateway($refund);
    }

    /** Repeats the gateway call for a refund that is processing but never got a gateway id (the first call could not be completed). */
    public function resume(int $refundId): ?Refund
    {
        $refund = Refund::query()->whereKey($refundId)->first();
        if ($refund === null || $refund->status !== RefundStatus::Processing || $refund->provider_refund_id !== null || $refund->method !== RefundMethod::Gateway) {
            return null;
        }

        return $this->callGateway($refund);
    }

    private function callGateway(Refund $refund): Refund
    {
        $tx = PaymentTransaction::query()->whereKey((int) $refund->payment_transaction_id)->first();
        if ($tx === null || $tx->provider_txn_id === null) {
            return $this->failed($refund, 'no_bank_transaction_id');
        }

        try {
            $result = $this->gateways->for($tx->provider)->refund(new RefundRequest($tx->provider_txn_id, $refund->refund_ref, Money::of($refund->amount_minor, $refund->currency), $refund->reason, $refund->public_id));
        } catch (PaymentException $e) {
            Log::warning('payments.refund_not_sent', ['refund' => $refund->public_id, 'reason' => $e->reason]);

            return $refund; // stays processing; the same call (same refund id) is repeated later
        }

        if ($result->state === 'failed') {
            return $this->failed($refund, $result->failureReason ?? 'gateway_refused');
        }

        if ($result->providerRefundId !== null) {
            Transactions::run(fn () => Refund::query()->whereKey($refund->id)->whereNull('provider_refund_id')->update(['provider_refund_id' => $result->providerRefundId, 'updated_at' => now()]));
        }

        return $refund->refresh();
    }

    private function failed(Refund $refund, string $reason): Refund
    {
        $refund = Transactions::run(function () use ($refund, $reason): Refund {
            $peek = Refund::query()->whereKey($refund->id)->firstOrFail();
            Payment::query()->whereKey($peek->payment_id)->lockForUpdate()->firstOrFail();
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === RefundStatus::Processing || $locked->status === RefundStatus::Approved) {
                $locked->forceFill(['status' => RefundStatus::Failed, 'failure_reason' => mb_substr($reason, 0, 255)])->save();
                $this->ledger->release($locked);
            }

            return $locked;
        });
        $this->audit->record('refund.failed', $refund, [], ['reason' => $reason], Actor::system('payments'));

        return $refund;
    }
}
