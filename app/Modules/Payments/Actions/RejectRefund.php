<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\RefundLedger;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;

/** Turns a refund request down (with a reason) and gives its amount back to what can still be refunded. */
final class RejectRefund
{
    public function __construct(private readonly RefundLedger $ledger, private readonly AuditLogger $audit) {}

    /** @throws PaymentException */
    public function handle(Actor $actor, string $refundPublicId, string $reason): Refund
    {
        $reason = mb_substr(trim(BanglaText::input($reason)), 0, 255);
        if ($reason === '') {
            throw new PaymentException('refund_reason_required');
        }

        $refund = Transactions::run(function () use ($refundPublicId, $reason): Refund {
            $peek = Refund::query()->where('public_id', $refundPublicId)->first() ?? throw new PaymentException('refund_not_found');
            Payment::query()->whereKey($peek->payment_id)->lockForUpdate()->firstOrFail();   // the payment first, then the refund (the same order everywhere)
            $refund = Refund::query()->whereKey($peek->id)->lockForUpdate()->firstOrFail();
            if ($refund->status !== RefundStatus::Requested) {
                throw new PaymentException('refund_not_pending');
            }

            $refund->forceFill(['status' => RefundStatus::Rejected, 'failure_reason' => $reason])->save();
            $this->ledger->release($refund);

            return $refund;
        });

        $this->audit->record('refund.rejected', $refund, ['status' => 'requested'], ['status' => 'rejected', 'reason' => $reason], $actor);

        return $refund;
    }
}
