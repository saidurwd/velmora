<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Api;

use App\Modules\Orders\Contracts\ApiOrderAccess;
use App\Modules\Orders\Contracts\OrderPayables;
use App\Modules\Orders\DTOs\OrderAccess;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use App\Modules\Platform\Http\Resources\ApiTypes;
use App\Modules\Platform\Http\Resources\MoneyData;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paying for an order from the app. The app never reports that it paid: it opens the gateway page, and then asks THIS server, which asks the gateway server
 * to server (amount, currency, reference and status) and records the answer. Nothing the app or the browser says is believed.
 */
class PaymentController
{
    private const STATUS = 'array{state: string, paid: bool, can_pay: bool, method: string|null, amount: '.ApiTypes::MONEY.'|null, attempts: int, last_attempt: array{status: string, at: string}|null, expires_at: string|null}';

    public function __construct(private readonly ApiOrderAccess $access, private readonly OrderPayables $payables) {}

    /**
     * Start (or retry) an online payment
     *
     * Needs an `Idempotency-Key` header. Answers `redirect_url`: open it in an in-app browser; the person pays at the gateway. When the browser closes, or comes back to the shop's site, call `GET /orders/{id}/payment` (and `POST /orders/{id}/payment/verify` to ask the gateway right away). A failed or abandoned attempt can be retried by calling this again with a new key. An order that is paid, cancelled or not an online order answers `order.already_paid`.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{redirect_url: string, method: string}}')]
    public function initiate(Request $request, string $id, InitiatePayment $initiate): JsonResponse
    {
        $access = $this->grant($request, $id);
        $payable = $this->payables->payable($access->publicId) ?? throw new ApiProblem(ErrorCode::OrderAlreadyPaid);

        try {
            $url = $initiate->handle($payable);
        } catch (PaymentException $e) {
            throw $this->problem($e);
        }

        return response()->json(['data' => ['redirect_url' => $url, 'method' => $payable->methodCode]]);
    }

    /**
     * Payment status
     *
     * What this server has recorded, never what the app says. `paid` is true only after the gateway confirmed the full amount in the right currency. `can_pay` says whether starting a payment makes sense now.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: '.self::STATUS.'}')]
    public function status(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->state($this->grant($request, $id))]);
    }

    /**
     * Ask the gateway now
     *
     * Makes the server ask the gateway about this order's open attempts (server to server) instead of waiting for the gateway's own notification. Safe to repeat: a payment is only ever applied once. Returns the same as the status call.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: '.self::STATUS.'}')]
    public function verify(Request $request, string $id, VerifyPayment $verify): JsonResponse
    {
        $access = $this->grant($request, $id);
        $payment = Payment::query()->where('order_id', $access->orderId)->orderByDesc('id')->first();

        if ($payment !== null) {
            foreach (PaymentTransaction::query()->where('payment_id', $payment->id)->whereIn('status', ['initiated', 'pending'])->orderByDesc('attempt')->pluck('merchant_ref') as $ref) {
                if ($verify->handle((string) $ref)->isPaid()) {
                    break;
                }
            }
        }

        return response()->json(['data' => $this->state($access)]);
    }

    /** @return array<string, mixed> */
    private function state(OrderAccess $access): array
    {
        $payment = Payment::query()->where('order_id', $access->orderId)->orderByDesc('id')->first();   // index (order_id)

        if ($payment === null) {
            return ['state' => 'none', 'paid' => false, 'can_pay' => $this->payables->isPayable($access->orderId), 'method' => null, 'amount' => null, 'attempts' => 0, 'last_attempt' => null, 'expires_at' => null];
        }

        $last = PaymentTransaction::query()->where('payment_id', $payment->id)->orderByDesc('attempt')->first();   // index (payment_id, attempt)

        return [
            'state' => $payment->status->value,
            'paid' => $payment->status === PaymentState::Paid,
            'can_pay' => $payment->status !== PaymentState::Paid && $this->payables->isPayable($access->orderId),
            'method' => $payment->method_code,
            'amount' => MoneyData::from(Money::of($payment->amount_minor, $payment->currency)),
            'attempts' => PaymentTransaction::query()->where('payment_id', $payment->id)->count(),
            'last_attempt' => $last === null ? null : ['status' => $last->status->value, 'at' => ($last->created_at ?? now())->toIso8601String()],
            'expires_at' => $payment->expires_at?->toIso8601String(),
        ];
    }

    private function grant(Request $request, string $id): OrderAccess
    {
        return $this->access->find($request, $id) ?? throw new ApiProblem(ErrorCode::NotFound);
    }

    private function problem(PaymentException $e): ApiProblem
    {
        $code = match ($e->reason) {
            'already_paid' => ErrorCode::OrderAlreadyPaid,
            'payment_expired' => ErrorCode::PaymentExpired,
            'method_unavailable', 'method_not_found', 'payments_frozen' => ErrorCode::PaymentMethodUnavailable,
            'too_many_attempts' => ErrorCode::RateLimited,
            default => ErrorCode::PaymentUnavailable,
        };

        return new ApiProblem($code, [], $code === ErrorCode::RateLimited ? ['Retry-After' => '60'] : [], trans()->has('payments::payments.errors.'.$e->reason) ? (string) __('payments::payments.errors.'.$e->reason) : null);
    }
}
