<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Models\Refund;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the books of money given back. The rule: what is asked for, promised, in flight or done (`requested`, `approved`, `processing`,
 * `succeeded`) is "reserved", and the reserved total can never pass what was received. The payment's `refunded_minor` always equals the
 * reserved total of its MAIN paid attempt (the first one that succeeded), and a CHECK keeps it at or below `captured_minor`; money from an
 * extra paid attempt (a double payment) is limited by that attempt's own amount instead.
 *
 * Every method here expects to run inside the transaction that holds the payment's row lock.
 */
final class RefundLedger
{
    public function __construct(private readonly Clock $clock) {}

    /** The attempt whose money is "the payment": the first that succeeded. */
    public function primaryTransactionId(int $paymentId): ?int
    {
        $id = DB::table('payment_transactions')->where('payment_id', $paymentId)->where('status', 'succeeded')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return list<string> */
    public function reservingStatuses(): array
    {
        return array_values(array_map(fn (RefundStatus $s) => $s->value, array_filter(RefundStatus::cases(), fn (RefundStatus $s) => $s->reservesMoney())));
    }

    /** Reserved total on one attempt. */
    public function reservedOnTransaction(int $transactionId): int
    {
        return (int) DB::table('refunds')->where('payment_transaction_id', $transactionId)->whereIn('status', $this->reservingStatuses())->sum('amount_minor');
    }

    /** Takes `amount` from what is still refundable on the main attempt. Returns false when there is not enough: nothing changes. */
    public function reserveOnPayment(int $paymentId, int $amount): bool
    {
        return DB::update(
            'UPDATE payments SET refunded_minor = refunded_minor + ?, updated_at = ? WHERE id = ? AND refunded_minor + ? <= captured_minor',
            [$amount, $this->clock->now()->format('Y-m-d H:i:s.u'), $paymentId, $amount],
        ) === 1;
    }

    /** Gives a refund's amount back to what is refundable (it was rejected, or the gateway could not do it). */
    public function release(Refund $refund): void
    {
        $primary = $this->primaryTransactionId($refund->payment_id);
        if ($refund->payment_transaction_id !== null && $primary !== null && $refund->payment_transaction_id !== $primary) {
            return; // an extra attempt's refund never touched the payment's total
        }

        DB::update(
            'UPDATE payments SET refunded_minor = refunded_minor - ?, updated_at = ? WHERE id = ? AND refunded_minor >= ?',
            [$refund->amount_minor, $this->clock->now()->format('Y-m-d H:i:s.u'), $refund->payment_id, $refund->amount_minor],
        );
    }

    /** How much of the main attempt has actually been paid back (finished refunds only). */
    public function succeededOnTransaction(int $transactionId): int
    {
        return (int) DB::table('refunds')->where('payment_transaction_id', $transactionId)->where('status', RefundStatus::Succeeded->value)->sum('amount_minor');
    }
}
