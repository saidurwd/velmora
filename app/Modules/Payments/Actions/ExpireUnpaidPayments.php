<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Enums\VerifyOutcome;
use App\Modules\Payments\Events\PaymentExpired;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Outbox\Outbox;
use App\Modules\Platform\Support\Transactions;

/**
 * Online payments that stayed unfinished past their time limit are closed, which tells Orders to cancel the order and give
 * its stock back. Before closing one it asks the gateway one last time, so a customer who did pay is never expired. If the
 * gateway cannot be reached the payment is left alone and tried again next minute.
 *
 * Its attempts are left as they are (still "pending"): if the gateway reports a success later, that success can still be recorded
 * and sent to a person as a late payment, instead of being lost.
 */
final class ExpireUnpaidPayments
{
    public function __construct(private readonly VerifyPayment $verify, private readonly Clock $clock, private readonly Outbox $outbox) {}

    /** @return int how many payments were expired */
    public function handle(int $limit = 100): int
    {
        $due = Payment::query()->whereIn('status', [PaymentState::Initiated->value, PaymentState::Pending->value])
            ->where('expires_at', '<=', $this->clock->now()->format('Y-m-d H:i:s.u'))->orderBy('id')->limit($limit)->pluck('id');

        $expired = 0;
        foreach ($due as $paymentId) {
            $refs = PaymentTransaction::query()->where('payment_id', $paymentId)->whereIn('status', ['initiated', 'pending'])->orderByDesc('attempt')->pluck('merchant_ref');
            $stop = false;

            foreach ($refs as $ref) {
                $outcome = $this->verify->handle((string) $ref);
                if ($outcome === VerifyOutcome::ProviderError) {
                    $stop = true; // cannot ask right now: leave this payment for the next run

                    break;
                }
            }
            if ($stop) {
                continue;
            }

            $expired += Transactions::run(fn (): bool => $this->expire((int) $paymentId)) ? 1 : 0;
        }

        return $expired;
    }

    /** Closes the payment if it is still open (verification above may have settled it). */
    private function expire(int $paymentId): bool
    {
        $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
        if (! in_array($payment->status, [PaymentState::Initiated, PaymentState::Pending], true)) {
            return false;
        }

        $payment->forceFill(['status' => PaymentState::Expired])->save();
        $this->outbox->record(new PaymentExpired($payment->id, $payment->public_id, $payment->order_id, $payment->amount_minor, $payment->currency));

        return true;
    }
}
