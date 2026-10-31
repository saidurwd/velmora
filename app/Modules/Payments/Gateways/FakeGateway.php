<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\DTOs\CallbackEvent;
use App\Modules\Payments\DTOs\Capabilities;
use App\Modules\Payments\DTOs\InitiationResult;
use App\Modules\Payments\DTOs\PaymentIntent;
use App\Modules\Payments\DTOs\RefundRequest;
use App\Modules\Payments\DTOs\RefundResult;
use App\Modules\Payments\DTOs\RefundStatusResult;
use App\Modules\Payments\DTOs\VerificationRequest;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use Illuminate\Http\Request;

/**
 * A pretend payment provider for tests and local work. It never touches a network. Tests tell it what the "provider" will answer
 * (`answer()`), so every hostile case (forged, late, wrong amount ...) can be played out exactly. Not registered in production.
 */
final class FakeGateway implements PaymentGateway
{
    /** @var list<PaymentIntent> */
    public array $initiated = [];

    /** @var list<VerificationRequest> */
    public array $verifications = [];

    /** @var list<RefundRequest> */
    public array $refunds = [];

    /** @var array<string, VerifiedPayment> */
    private array $answers = [];

    /** @var array<string, string> */
    private array $refundStates = [];

    private ?string $refundRefusal = null;

    /** @var array<string, string> a method name => the reason it should fail with, once */
    private array $failures = [];

    public function __construct(private Capabilities $capabilities = new Capabilities(refund: true, partialRefund: true, refundStatus: true, statusQuery: true, signedCallback: false, sandbox: true, minAmountMinor: 1000, maxAmountMinor: 50_000_000)) {}

    public function withCapabilities(Capabilities $capabilities): self
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    /** What the provider will say about this merchant reference from now on. */
    public function answer(string $merchantRef, VerifiedPayment $answer): self
    {
        $this->answers[$merchantRef] = $answer;

        return $this;
    }

    public function refundWillBe(string $providerRefundId, string $state): self
    {
        $this->refundStates[$providerRefundId] = $state;

        return $this;
    }

    /** The next refund is refused by the "gateway" (it answers, but says it failed), once. */
    public function refuseNextRefund(string $reason = 'Refund amount exceeds the transaction amount'): self
    {
        $this->refundRefusal = $reason;

        return $this;
    }

    /** The next call to this method ('initiate', 'verify', 'status', 'refund', 'refundStatus') fails once with a payment error. */
    public function failNext(string $method, string $reason = 'provider_unavailable'): self
    {
        $this->failures[$method] = $reason;

        return $this;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function initiate(PaymentIntent $intent): InitiationResult
    {
        $this->maybeFail('initiate');
        $this->initiated[] = $intent;

        return new InitiationResult('https://fake-gateway.test/pay/'.rawurlencode($intent->merchantRef), 'FAKE-'.$intent->merchantRef, ['merchant_ref' => $intent->merchantRef, 'amount' => $intent->amount->toMajorString()]);
    }

    public function parseCallback(Request $request): CallbackEvent
    {
        $ref = $request->input('tran_id');
        $status = $request->input('status');
        $validation = $request->input('val_id');
        $payload = array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $request->except(['store_passwd']));

        if (! is_string($ref) && ! is_string($validation)) {
            throw new PaymentException('callback_unreadable');
        }

        return new CallbackEvent(
            (string) ($request->input('event_id') ?: hash('sha256', 'fake|'.$ref.'|'.$status.'|'.$request->input('amount').'|'.$validation)),
            is_string($ref) ? $ref : null, is_string($status) ? $status : null, is_string($validation) ? $validation : null, $payload,
        );
    }

    public function verify(VerificationRequest $request): VerifiedPayment
    {
        $this->maybeFail('verify');
        $this->verifications[] = $request;

        return $this->answers[$request->merchantRef] ?? new VerifiedPayment(VerifiedStatus::Unknown, $request->merchantRef, null);
    }

    public function status(string $merchantRef): VerifiedPayment
    {
        $this->maybeFail('status');

        return $this->answers[$merchantRef] ?? new VerifiedPayment(VerifiedStatus::Unknown, $merchantRef, null);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $this->maybeFail('refund');
        if (! $this->capabilities->refund) {
            throw new PaymentException('refund_not_supported');
        }
        $this->refunds[] = $request;
        if ($this->refundRefusal !== null) {
            $reason = $this->refundRefusal;
            $this->refundRefusal = null;

            return new RefundResult('failed', null, $reason);
        }

        return new RefundResult('accepted', 'FAKEREF-'.$request->refundRef);
    }

    public function refundStatus(string $providerRefundId): RefundStatusResult
    {
        $this->maybeFail('refundStatus');

        return new RefundStatusResult($this->refundStates[$providerRefundId] ?? 'refunded');
    }

    private function maybeFail(string $method): void
    {
        if (isset($this->failures[$method])) {
            $reason = $this->failures[$method];
            unset($this->failures[$method]);

            throw new PaymentException($reason);
        }
    }
}
