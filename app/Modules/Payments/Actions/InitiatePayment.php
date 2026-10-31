<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Payments\DTOs\PaymentIntent;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Enums\TransactionStatus;
use App\Modules\Payments\Events\PaymentInitiated;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\DatabasePaymentMethodCatalog;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Outbox\Outbox;
use App\Modules\Platform\Support\Settings;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\URL;

/**
 * Starts (or restarts) the online payment of an order and returns where to send the customer. In three short steps so that no
 * database lock is ever held while the gateway is being called: (1) open the payment and a numbered attempt, (2) ask the
 * gateway, (3) record its answer. A retry after a failure is a new attempt on the same payment.
 */
final class InitiatePayment
{
    private const MAX_ATTEMPTS = 99;

    private const REUSE_SECONDS = 120;

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly Clock $clock,
        private readonly Outbox $outbox,
        private readonly Settings $settings,
    ) {}

    /** @throws PaymentException */
    public function handle(OrderPaymentRequest $order): string
    {
        $method = PaymentMethod::query()->where('code', $order->methodCode)->where('is_enabled', true)->first();
        if ($method === null || $method->type === 'cod') {
            throw new PaymentException('method_unavailable');
        }
        if ($this->settings->bool(DatabasePaymentMethodCatalog::FREEZE_SETTING, false)) {
            throw new PaymentException('payments_frozen');
        }
        if ($order->currency !== 'BDT') {
            throw new PaymentException('currency_unsupported');
        }

        $gateway = $this->gateways->for($method->provider);
        $caps = $gateway->capabilities();
        if (($method->min_minor !== null && $order->amountMinor < $method->min_minor) || ($method->max_minor !== null && $order->amountMinor > $method->max_minor)
            || ($caps->minAmountMinor !== null && $order->amountMinor < $caps->minAmountMinor) || ($caps->maxAmountMinor !== null && $order->amountMinor > $caps->maxAmountMinor)) {
            throw new PaymentException('amount_out_of_range');
        }

        // pressed twice (a double-click, two tabs): the same payment page, not a second gateway session that could be paid as well
        $already = $this->recentRedirect($order->orderId);
        if ($already !== null) {
            return $already;
        }

        [$paymentId, $paymentPublicId, $txId, $merchantRef] = $this->openAttempt($order, $method);
        $intent = $this->intent($order, $paymentPublicId, $merchantRef, $method->provider);

        try {
            $result = $gateway->initiate($intent);
        } catch (PaymentException $e) {
            $this->attemptFailed($paymentId, $txId, $e->reason);

            throw new PaymentException('initiation_failed');
        }

        Transactions::run(function () use ($paymentId, $txId, $result, $order): void {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
            $tx = PaymentTransaction::query()->whereKey($txId)->firstOrFail();

            $tx->forceFill(['provider_session' => $result->sessionKey, 'request_summary' => $result->summary + ['redirect_url' => $result->redirectUrl], 'status' => TransactionStatus::Pending])->save();
            if (in_array($payment->status, [PaymentState::Initiated, PaymentState::Failed, PaymentState::Cancelled], true)) {
                $payment->forceFill(['status' => PaymentState::Pending])->save();
            }

            $this->outbox->record(new PaymentInitiated($payment->id, $payment->public_id, $order->orderId, $payment->amount_minor, $payment->currency, $tx->merchant_ref));
        });

        return $result->redirectUrl;
    }

    /** Where the customer was sent a moment ago for this order, if an attempt was started in the last two minutes and is still waiting. */
    private function recentRedirect(int $orderId): ?string
    {
        $paymentId = Payment::query()->where('order_id', $orderId)->orderByDesc('id')->value('id');
        if ($paymentId === null) {
            return null;
        }

        $tx = PaymentTransaction::query()->where('payment_id', $paymentId)->where('status', TransactionStatus::Pending->value)
            ->where('created_at', '>=', $this->clock->now()->subSeconds(self::REUSE_SECONDS)->format('Y-m-d H:i:s.u'))->orderByDesc('attempt')->first();
        $url = $tx?->request_summary['redirect_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** @return array{int, string, int, string} payment id, payment public id, transaction id, merchant reference */
    private function openAttempt(OrderPaymentRequest $order, PaymentMethod $method): array
    {
        try {
            return Transactions::run(fn () => $this->openAttemptOnce($order, $method));
        } catch (UniqueConstraintViolationException) {
            // another checkout opened this order's payment at the same instant: use theirs
            return Transactions::run(fn () => $this->openAttemptOnce($order, $method));
        }
    }

    /** @return array{int, string, int, string} */
    private function openAttemptOnce(OrderPaymentRequest $order, PaymentMethod $method): array
    {
        $now = $this->clock->now();
        $payment = Payment::query()->where('order_id', $order->orderId)->orderByDesc('id')->lockForUpdate()->first();

        if ($payment === null) {
            $payment = Payment::query()->create([
                'order_id' => $order->orderId, 'method_code' => $method->code, 'provider' => $method->provider, 'amount_minor' => $order->amountMinor, 'currency' => $order->currency,
                'status' => PaymentState::Initiated, 'expires_at' => $now->addMinutes((int) config('payments.expiry_minutes')),
            ]);
        } else {
            match ($payment->status) {
                PaymentState::Paid => throw new PaymentException('already_paid'),
                PaymentState::NeedsReview => throw new PaymentException('under_review'),
                PaymentState::Expired => throw new PaymentException('payment_expired'),
                default => $payment->forceFill(['expires_at' => $now->addMinutes((int) config('payments.expiry_minutes'))])->save(),   // a retry gets a fresh time limit
            };
        }

        $attempt = (int) PaymentTransaction::query()->where('payment_id', $payment->id)->max('attempt') + 1;
        if ($attempt > self::MAX_ATTEMPTS) {
            throw new PaymentException('too_many_attempts');
        }

        $tx = PaymentTransaction::query()->create([
            'payment_id' => $payment->id, 'provider' => $method->provider, 'merchant_ref' => $order->orderNumber.'-'.$attempt, 'attempt' => $attempt,
            'status' => TransactionStatus::Initiated, 'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency,
        ]);

        return [$payment->id, $payment->public_id, $tx->id, $tx->merchant_ref];
    }

    private function attemptFailed(int $paymentId, int $txId, string $reason): void
    {
        Transactions::run(function () use ($paymentId, $txId, $reason): void {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
            PaymentTransaction::query()->whereKey($txId)->firstOrFail()->forceFill(['status' => TransactionStatus::Failed, 'failure_code' => mb_substr($reason, 0, 60)])->save();

            if ($payment->status === PaymentState::Initiated) {
                $payment->forceFill(['status' => PaymentState::Failed])->save();   // nothing was ever started: the customer can try again
            }
        });
    }

    private function intent(OrderPaymentRequest $order, string $paymentPublicId, string $merchantRef, string $provider): PaymentIntent
    {
        $until = $this->clock->now()->addMinutes((int) config('payments.expiry_minutes'))->addDay();
        $back = fn (string $outcome): string => URL::temporarySignedRoute('payments.return', $until, ['locale' => $order->locale, 'payment' => $paymentPublicId, 'outcome' => $outcome]);

        return new PaymentIntent(
            $merchantRef, Money::of($order->amountMinor, $order->currency), $order->orderNumber, $order->customerName, $order->customerPhone, $order->customerEmail,
            $order->addressLine ?? 'N/A', $order->city ?? 'N/A', $order->postcode, $order->itemCount, $order->needsShipping, $order->productSummary,
            $back('success'), $back('fail'), $back('cancel'), route('api.v1.webhooks.payments', ['provider' => $provider]), ['payment' => $paymentPublicId],
        );
    }
}
