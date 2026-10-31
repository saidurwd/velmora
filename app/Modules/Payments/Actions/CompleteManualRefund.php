<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Events\RefundSucceeded;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\RefundLedger;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Outbox\Outbox;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;

/** A person paid the money back outside the gateway (bKash, bank, cash) and records the proof (a reference or slip number). */
final class CompleteManualRefund
{
    public function __construct(private readonly RefundLedger $ledger, private readonly Outbox $outbox, private readonly AuditLogger $audit) {}

    /** @throws PaymentException */
    public function handle(Actor $actor, string $refundPublicId, string $proofRef): Refund
    {
        $proof = mb_substr(trim(BanglaText::input($proofRef)), 0, 191);
        if ($proof === '') {
            throw new PaymentException('refund_proof_required');
        }

        $refund = Transactions::run(function () use ($refundPublicId, $proof): Refund {
            $peek = Refund::query()->where('public_id', $refundPublicId)->first() ?? throw new PaymentException('refund_not_found');
            $payment = Payment::query()->whereKey($peek->payment_id)->lockForUpdate()->firstOrFail();
            $refund = Refund::query()->whereKey($peek->id)->lockForUpdate()->firstOrFail();
            if ($refund->method !== RefundMethod::Manual) {
                throw new PaymentException('refund_wrong_method');
            }
            if ($refund->status !== RefundStatus::Approved) {
                throw new PaymentException('refund_not_approved');
            }

            $refund->forceFill(['status' => RefundStatus::Succeeded, 'proof_ref' => $proof])->save();
            $this->announce($refund, $payment);

            return $refund;
        });
        $this->audit->record('refund.completed_manually', $refund, ['status' => 'approved'], ['status' => 'succeeded', 'proof_ref' => $proof, 'amount_minor' => $refund->amount_minor], $actor);

        return $refund;
    }

    /** Tells the order (through the outbox, in the same transaction) what the refund means for it. Call with the payment row locked. */
    public function announce(Refund $refund, Payment $payment): void
    {
        $primary = $this->ledger->primaryTransactionId($payment->id);
        $detail = match (true) {
            $refund->payment_transaction_id !== $primary => 'extra',
            $this->ledger->succeededOnTransaction((int) $refund->payment_transaction_id) >= $payment->captured_minor => 'full',
            default => 'partial',
        };

        $this->outbox->record(new RefundSucceeded($payment->id, $payment->public_id, $payment->order_id, $refund->amount_minor, $payment->currency, $refund->public_id, $detail));
    }
}
