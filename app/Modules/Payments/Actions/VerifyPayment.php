<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Orders\Contracts\OrderPayables;
use App\Modules\Payments\DTOs\VerificationRequest;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Enums\TransactionStatus;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Enums\VerifyOutcome;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentNeedsReview;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Payments\Services\PaymentExceptions;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Outbox\Outbox;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * The only place a payment becomes "paid".
 *
 * It asks the gateway directly (server to server) about OUR transaction, found by OUR reference; anything the browser or a
 * notification said is at most a hint for the question. Then, inside one transaction holding the payment's row lock, it checks
 * that the gateway's answer is about this reference, for exactly this amount in this currency, before it moves any money state.
 * Anything that does not match goes to a person ("needs review") and is never paid. Running it again, or twice at the same
 * moment, changes nothing the second time.
 */
final class VerifyPayment
{
    private const REVERIFY_DAYS = 7;

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly Clock $clock,
        private readonly Outbox $outbox,
        private readonly PaymentExceptions $exceptions,
        private readonly OrderPayables $orders,
    ) {}

    public function handle(string $merchantRef, ?string $validationHint = null): VerifyOutcome
    {
        $tx = PaymentTransaction::query()->where('merchant_ref', $merchantRef)->first();
        if ($tx === null) {
            return VerifyOutcome::UnknownReference;
        }
        // Paid is settled for good. A closed-but-unpaid attempt is still worth asking about for a week (a late success must not be lost),
        // after which replays cannot make us call the gateway any more.
        if ($tx->status->isFinal() || ($tx->status->isClosed() && $tx->created_at !== null && $tx->created_at->lt($this->clock->now()->subDays(self::REVERIFY_DAYS)))) {
            return $this->settled($tx->status);
        }

        try {
            $answer = $this->gateways->for($tx->provider)->verify(new VerificationRequest($tx->merchant_ref, $validationHint, $tx->provider_session));
        } catch (PaymentException $e) {
            Log::warning('payments.verify_unavailable', ['merchant_ref' => $tx->merchant_ref, 'provider' => $tx->provider, 'reason' => $e->reason]);

            return VerifyOutcome::ProviderError; // we could not ask: nothing is concluded, reconciliation will ask again
        }

        return Transactions::run(fn (): VerifyOutcome => $this->apply($tx->id, $answer));
    }

    private function apply(int $txId, VerifiedPayment $answer): VerifyOutcome
    {
        $paymentId = (int) PaymentTransaction::query()->whereKey($txId)->value('payment_id');
        $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();   // always the payment first, then its attempts
        $tx = PaymentTransaction::query()->whereKey($txId)->firstOrFail();

        if ($tx->status->isFinal()) {
            return $this->settled($tx->status); // another process got here first
        }
        if ($answer->merchantRef !== $tx->merchant_ref) {
            return $this->hold($payment, $tx, 'unmatched_provider_txn', 'reference_mismatch', $answer);
        }
        if ($tx->status->isClosed() && $answer->status !== VerifiedStatus::Valid) {
            return $this->settled($tx->status); // already failed/cancelled/expired and the gateway says nothing new
        }

        return match ($answer->status) {
            VerifiedStatus::Valid => $this->received($payment, $tx, $answer),
            VerifiedStatus::Pending => $this->stillWaiting($tx),
            VerifiedStatus::Failed, VerifiedStatus::Cancelled, VerifiedStatus::Expired => $this->declined($payment, $tx, $answer->status),
            VerifiedStatus::Unknown => VerifyOutcome::NoChange,
        };
    }

    private function received(Payment $payment, PaymentTransaction $tx, VerifiedPayment $answer): VerifyOutcome
    {
        if ($answer->amount === null || $answer->amount->currency !== $tx->currency) {
            return $this->hold($payment, $tx, 'currency_mismatch', 'currency_mismatch', $answer);
        }
        if ($answer->amount->amountMinor !== $tx->amount_minor) {
            return $this->hold($payment, $tx, 'amount_mismatch', 'amount_mismatch', $answer);
        }
        if ($answer->providerTxnId === null || $answer->providerTxnId === '') {
            return $this->hold($payment, $tx, 'unmatched_provider_txn', 'missing_provider_transaction_id', $answer); // without it we could never refund
        }

        $now = $this->clock->now();
        try {
            $tx->forceFill(['status' => TransactionStatus::Succeeded, 'provider_txn_id' => $answer->providerTxnId, 'provider_validation_id' => $answer->providerValidationId, 'verified_at' => $now])->save();
        } catch (UniqueConstraintViolationException) {
            return $this->hold($payment, $tx, 'unmatched_provider_txn', 'provider_transaction_already_used', $answer); // the bank transaction belongs to another attempt
        }

        if ($payment->status === PaymentState::Paid) {
            // a second attempt on the same payment was also paid: the order is covered, the extra money has to go back
            $this->exceptions->raise('duplicate_payment', $payment->id, $payment->order_id, $answer->providerTxnId, ['merchant_ref' => $tx->merchant_ref, 'amount_minor' => $tx->amount_minor]);

            return VerifyOutcome::Paid;
        }

        $payable = in_array($payment->status, [PaymentState::Initiated, PaymentState::Pending, PaymentState::Failed, PaymentState::Cancelled], true) && $this->orders->isPayable($payment->order_id);
        if ($payable) {
            $this->settle($payment, PaymentState::Paid, $tx->amount_minor, $now);
            $this->outbox->record(new PaymentSucceeded($payment->id, $payment->public_id, $payment->order_id, $payment->amount_minor, $payment->currency, $tx->merchant_ref, $answer->riskFlag ? 'risk_high' : null));
            $this->flagExtraPayments($payment, $tx, $answer);

            return VerifyOutcome::Paid;
        }

        // the money is real but the order cannot take it any more (it expired and was cancelled, or someone is already looking): a person decides
        $was = $payment->status->value;
        $this->settle($payment, PaymentState::NeedsReview, $tx->amount_minor, $now);
        $this->exceptions->raise('late_success', $payment->id, $payment->order_id, $answer->providerTxnId, ['merchant_ref' => $tx->merchant_ref, 'amount_minor' => $tx->amount_minor, 'payment_was' => $was]);
        $this->outbox->record(new PaymentNeedsReview($payment->id, $payment->public_id, $payment->order_id, $payment->amount_minor, $payment->currency, $tx->merchant_ref, 'late_success'));

        return VerifyOutcome::NeedsReview;
    }

    /** The gateway reported more than one successful payment under our one reference: the extra money has to go back. */
    private function flagExtraPayments(Payment $payment, PaymentTransaction $tx, VerifiedPayment $answer): void
    {
        $count = (int) ($answer->raw['valid_count'] ?? 1);
        if ($count > 1) {
            $this->exceptions->raise('duplicate_payment', $payment->id, $payment->order_id, $tx->merchant_ref.'#multi', ['merchant_ref' => $tx->merchant_ref, 'successful_payments' => $count, 'amount_minor_each' => $tx->amount_minor]);
        }
    }

    private function stillWaiting(PaymentTransaction $tx): VerifyOutcome
    {
        if ($tx->status === TransactionStatus::Initiated) {
            $tx->forceFill(['status' => TransactionStatus::Pending])->save();
        }

        return VerifyOutcome::Pending;
    }

    private function declined(Payment $payment, PaymentTransaction $tx, VerifiedStatus $why): VerifyOutcome
    {
        $status = match ($why) {
            VerifiedStatus::Cancelled => TransactionStatus::Cancelled,
            VerifiedStatus::Expired => TransactionStatus::Expired,
            default => TransactionStatus::Failed,
        };
        $tx->forceFill(['status' => $status, 'failure_code' => 'provider_'.$why->value])->save();

        // the payment as a whole fails only if no other attempt is still in flight
        $otherOpen = PaymentTransaction::query()->where('payment_id', $payment->id)->where('id', '!=', $tx->id)->whereIn('status', ['initiated', 'pending'])->exists();
        if (! $otherOpen && in_array($payment->status, [PaymentState::Initiated, PaymentState::Pending], true)) {
            $payment->forceFill(['status' => $why === VerifiedStatus::Cancelled ? PaymentState::Cancelled : PaymentState::Failed])->save();
            $this->outbox->record(new PaymentFailed($payment->id, $payment->public_id, $payment->order_id, $payment->amount_minor, $payment->currency, $tx->merchant_ref, $why->value));
        }

        return $status === TransactionStatus::Cancelled ? VerifyOutcome::Cancelled : VerifyOutcome::Failed;
    }

    /** The gateway's answer does not match our records: nothing is paid, a person is told. */
    private function hold(Payment $payment, PaymentTransaction $tx, string $exceptionType, string $reason, VerifiedPayment $answer): VerifyOutcome
    {
        $this->exceptions->raise($exceptionType, $payment->id, $payment->order_id, $tx->merchant_ref, [
            'reason' => $reason, 'expected_minor' => $tx->amount_minor, 'expected_currency' => $tx->currency,
            'got_minor' => $answer->amount?->amountMinor, 'got_currency' => $answer->amount?->currency, 'got_reference' => $answer->merchantRef, 'provider_txn_id' => $answer->providerTxnId,
        ]);

        if ($payment->status !== PaymentState::Paid && $payment->status !== PaymentState::NeedsReview) {
            $this->settle($payment, PaymentState::NeedsReview, null, null);
            $this->outbox->record(new PaymentNeedsReview($payment->id, $payment->public_id, $payment->order_id, $payment->amount_minor, $payment->currency, $tx->merchant_ref, $reason));
        }

        return VerifyOutcome::NeedsReview;
    }

    /** Moves the payment to a new state, going through "pending" where the rules require it (failed/cancelled cannot jump straight on). */
    private function settle(Payment $payment, PaymentState $to, ?int $capturedMinor, ?CarbonImmutable $paidAt): void
    {
        if (in_array($payment->status, [PaymentState::Failed, PaymentState::Cancelled], true)) {
            $payment->forceFill(['status' => PaymentState::Pending])->save();
        }

        $changes = ['status' => $to];
        if ($capturedMinor !== null) {
            $changes += ['captured_minor' => $capturedMinor, 'paid_at' => $paidAt];
        }
        $payment->forceFill($changes)->save();
    }

    private function settled(TransactionStatus $status): VerifyOutcome
    {
        return match ($status) {
            TransactionStatus::Succeeded => VerifyOutcome::Paid,
            TransactionStatus::Failed => VerifyOutcome::Failed,
            TransactionStatus::Cancelled => VerifyOutcome::Cancelled,
            default => VerifyOutcome::NoChange,
        };
    }
}
