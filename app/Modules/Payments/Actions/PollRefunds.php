<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Payments\Services\PaymentExceptions;
use App\Modules\Payments\Services\RefundLedger;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;

/**
 * Follows refunds that are with the gateway until it says what happened: "refunded" → succeeded, "cancelled" → failed (the amount can be
 * asked for again), "processing" → leave it. A refund the gateway never acknowledged is sent again (same refund id). One that has been
 * processing for days is raised for finance.
 */
final class PollRefunds
{
    public const STUCK_AFTER_DAYS = 3;

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly ExecuteRefund $execute,
        private readonly CompleteManualRefund $complete,
        private readonly RefundLedger $ledger,
        private readonly PaymentExceptions $exceptions,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /** @return array{succeeded: int, failed: int, resent: int, stuck: int} */
    public function handle(int $limit = 100): array
    {
        $out = ['succeeded' => 0, 'failed' => 0, 'resent' => 0, 'stuck' => 0];
        $open = Refund::query()->where('status', RefundStatus::Processing->value)->where('method', RefundMethod::Gateway->value)->orderBy('id')->limit($limit)->get();

        foreach ($open as $refund) {
            if ($refund->provider_refund_id === null) {
                if ($refund->updated_at !== null && $refund->updated_at->lt($this->clock->now()->subSeconds(60))) {
                    $this->execute->resume($refund->id);
                    $out['resent']++;
                }

                continue;
            }

            $tx = PaymentTransaction::query()->whereKey((int) $refund->payment_transaction_id)->first();
            if ($tx === null) {
                continue;
            }

            try {
                $state = $this->gateways->for($tx->provider)->refundStatus($refund->provider_refund_id)->state;
            } catch (PaymentException) {
                continue; // cannot ask right now; next time
            }

            match ($state) {
                'refunded' => $this->settle($refund, true, $out),
                'cancelled' => $this->settle($refund, false, $out),
                default => $this->stuck($refund, $out),
            };
        }

        return $out;
    }

    /** @param  array{succeeded: int, failed: int, resent: int, stuck: int}  $out */
    private function settle(Refund $refund, bool $done, array &$out): void
    {
        $result = Transactions::run(function () use ($refund, $done): bool {
            $payment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RefundStatus::Processing) {
                return false; // settled meanwhile
            }

            if ($done) {
                $locked->forceFill(['status' => RefundStatus::Succeeded])->save();
                $this->complete->announce($locked, $payment);
            } else {
                $locked->forceFill(['status' => RefundStatus::Failed, 'failure_reason' => 'cancelled_by_gateway'])->save();
                $this->ledger->release($locked);
            }

            return true;
        });

        if ($result) {
            $this->audit->record($done ? 'refund.succeeded' : 'refund.failed', $refund, [], ['status' => $done ? 'succeeded' : 'failed'], Actor::system('payments'));
            $out[$done ? 'succeeded' : 'failed']++;
        }
    }

    /** @param  array{succeeded: int, failed: int, resent: int, stuck: int}  $out */
    private function stuck(Refund $refund, array &$out): void
    {
        if ($refund->updated_at !== null && $refund->updated_at->lt($this->clock->now()->subDays(self::STUCK_AFTER_DAYS))) {
            $this->exceptions->raise('refund_stuck', $refund->payment_id, $refund->order_id, $refund->refund_ref, ['refund' => $refund->public_id, 'amount_minor' => $refund->amount_minor, 'since' => $refund->updated_at->toIso8601String()]);
            $out['stuck']++;
        }
    }
}
