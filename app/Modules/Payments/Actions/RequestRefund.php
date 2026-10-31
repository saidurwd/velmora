<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\RefundLedger;
use App\Modules\Payments\Services\RefundRouting;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Str;

/**
 * Asks for money to be given back. Under the payment's row lock it checks that the total of refunds (asked for, promised, in flight or
 * done) never passes what was received, and takes the amount in ONE conditional UPDATE, so two requests at the same instant cannot
 * both fit when only one does. Who may ask (permission `payments.refund`) is decided by the caller.
 */
final class RequestRefund
{
    public function __construct(private readonly RefundLedger $ledger, private readonly RefundRouting $routing, private readonly AuditLogger $audit) {}

    /**
     * @param  string|null  $transactionPublicId  a specific paid attempt (to give back a double payment); default is the main one
     *
     * @throws PaymentException
     */
    public function handle(Actor $actor, string $paymentPublicId, int $amountMinor, string $reason, ?string $transactionPublicId = null, bool $manual = false): Refund
    {
        $reason = mb_substr(trim(BanglaText::input($reason)), 0, 255);
        if ($reason === '') {
            throw new PaymentException('refund_reason_required');
        }
        if ($amountMinor < 1) {
            throw new PaymentException('refund_amount_invalid');
        }

        $refund = Transactions::run(function () use ($actor, $paymentPublicId, $amountMinor, $reason, $transactionPublicId, $manual): Refund {
            $payment = Payment::query()->where('public_id', $paymentPublicId)->lockForUpdate()->first() ?? throw new PaymentException('refund_not_found');
            if ($payment->captured_minor < 1 || ! in_array($payment->status, [PaymentState::Paid, PaymentState::NeedsReview], true)) {
                throw new PaymentException('refund_nothing_captured'); // nothing has been received that we know of
            }

            $paid = PaymentTransaction::query()->where('payment_id', $payment->id)->where('status', 'succeeded')->orderBy('id')->get();
            $primary = $paid->first() ?? throw new PaymentException('refund_nothing_captured');
            $target = $transactionPublicId === null ? $primary : ($paid->firstWhere('public_id', $transactionPublicId) ?? throw new PaymentException('refund_transaction_invalid'));

            if ($target->id === $primary->id) {
                if (! $this->ledger->reserveOnPayment($payment->id, $amountMinor)) {
                    throw new PaymentException('refund_exceeds_captured', ['left' => max(0, $payment->captured_minor - $payment->refunded_minor)]);
                }
            } elseif ($this->ledger->reservedOnTransaction($target->id) + $amountMinor > $target->amount_minor) {
                throw new PaymentException('refund_exceeds_captured', ['left' => max(0, $target->amount_minor - $this->ledger->reservedOnTransaction($target->id))]);
            }

            $refund = Refund::query()->create([
                'payment_id' => $payment->id, 'order_id' => $payment->order_id, 'payment_transaction_id' => $target->id, 'amount_minor' => $amountMinor, 'currency' => $payment->currency,
                'status' => RefundStatus::Requested, 'method' => $this->routing->decide($target, $amountMinor, $manual), 'reason' => $reason,
                'refund_ref' => 'RF-'.strtoupper(Str::random(20)), 'requested_by' => $actor->id,
            ]);

            return $refund;
        });

        $this->audit->record('refund.requested', $refund, [], ['amount_minor' => $amountMinor, 'method' => $refund->method->value, 'reason' => $reason], $actor);

        return $refund;
    }

    public function wouldBeManual(Refund $refund): bool
    {
        return $refund->method === RefundMethod::Manual;
    }
}
