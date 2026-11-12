<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Api;

use App\Modules\Cart\Contracts\ApiCartOwnerResolver;
use App\Modules\Cart\Contracts\CartPresenter;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cms\Contracts\PolicyAcceptances;
use App\Modules\Cms\DTOs\PolicyNotice;
use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\Orders\Actions\BuildCheckoutDraft;
use App\Modules\Orders\Actions\ConfirmCheckoutPhone;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Actions\ReviewCheckout;
use App\Modules\Orders\DTOs\CheckoutReview;
use App\Modules\Orders\DTOs\PlaceOrderCommand;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Orders\Exceptions\CheckoutException;
use App\Modules\Orders\Http\CheckoutProblems;
use App\Modules\Orders\Http\Requests\Api\CheckoutRequest;
use App\Modules\Orders\Http\Requests\Api\PhoneCodeRequest;
use App\Modules\Orders\Http\Requests\Api\PhoneConfirmRequest;
use App\Modules\Orders\Http\Requests\Api\PlaceOrderApiRequest;
use App\Modules\Orders\Http\Resources\CheckoutResource;
use App\Modules\Orders\Http\Resources\OrderSummary;
use App\Modules\Orders\Services\PhoneProof;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Checkout for the app. The same Actions as the website's checkout (BuildCheckoutDraft, ReviewCheckout, PlaceOrder) with the same rules; what differs is that
 * the app sends everything with each call, because there is no session. The app never sends a price: it sends the total it was shown, and the server
 * refuses (409) if the real total moved.
 */
class CheckoutController
{
    public function __construct(
        private readonly ApiCartOwnerResolver $owners,
        private readonly BuildCheckoutDraft $build,
        private readonly ReviewCheckout $review,
        private readonly PolicyAcceptances $policies,
        private readonly CartPresenter $carts,
        private readonly PhoneProof $proof,
    ) {}

    /**
     * Price the checkout
     *
     * Send the contact and the address (a saved `address.id`, or the address typed in full) and, optionally, the delivery and payment you want to see priced. The answer is what would be charged, how it would be delivered, the ways to pay, whether a phone code is needed (large cash orders), and the policies to accept. Nothing is saved and nothing is reserved. For a cart with nothing to deliver (digital goods, services) send `contact.name` and `contact.phone` instead of an address.
     *
     * @unauthenticated
     */
    #[Response(status: 200, type: 'array{data: '.CheckoutResource::TYPE.'}')]
    public function quote(CheckoutRequest $request): JsonResponse
    {
        $owner = $this->owners->fromRequest($request);

        try {
            $review = $this->reviewFor($owner, $request, $request->paymentMethod());
        } catch (CheckoutException $e) {
            throw $this->problem($e);
        } catch (DomainException $e) {
            throw $this->addressProblem($e);
        }

        return response()->json(['data' => CheckoutResource::make($review, $this->policies($owner, $review), app()->getLocale(), $this->carts)]);
    }

    /**
     * Send a code to the phone
     *
     * For a large cash-on-delivery order the phone number must be confirmed first. Sends a 6-digit code by text message. `status` is `sent` or `cooling_down` (a code was sent a moment ago and still works).
     *
     * @unauthenticated
     */
    #[Response(status: 202, type: 'array{data: array{status: string}}')]
    #[IgnoreResponse(200)]
    public function phoneCode(PhoneCodeRequest $request, ConfirmCheckoutPhone $confirm): JsonResponse
    {
        $status = $confirm->send($request->phone()->e164(), $request->ip(), app()->getLocale());

        return match ($status) {
            OtpIssueStatus::Sent => response()->json(['data' => ['status' => 'sent']], 202),
            OtpIssueStatus::CoolingDown => response()->json(['data' => ['status' => 'cooling_down']], 202),
            OtpIssueStatus::TargetLimited, OtpIssueStatus::IpLimited, OtpIssueStatus::DailyCapReached => throw new ApiProblem(ErrorCode::RateLimited),
            OtpIssueStatus::DeliveryFailed => throw new ApiProblem(ErrorCode::OtpUnavailable),
        };
    }

    /**
     * Confirm the phone with the code
     *
     * Every failure (wrong, expired, used up) answers the same `auth.otp_invalid`. On success you get a `phone_proof`: send it with the order. It is good for `expires_in` seconds and only for this phone number.
     *
     * @unauthenticated
     */
    #[Response(status: 200, type: 'array{data: array{phone_proof: string, expires_in: int}}')]
    public function phoneConfirm(PhoneConfirmRequest $request, ConfirmCheckoutPhone $confirm, PhoneProof $proof): JsonResponse
    {
        $phone = $request->phone()->e164();
        $confirm->verify($phone, (string) $request->validated('code')) || throw new ApiProblem(ErrorCode::OtpInvalid);

        return response()->json(['data' => ['phone_proof' => $proof->issue($phone), 'expires_in' => $proof->seconds()]]);
    }

    /**
     * Place the order
     *
     * Needs an `Idempotency-Key` header (8 to 100 letters, digits, `.`, `_`, `:` or `-`; make a new one for each order the person confirms and reuse it for every retry of that same order). The same key can never make a second order, however many times it is sent. `expected_total` is the `grand_total.amount_minor` the person was shown; if the real total differs the order is NOT placed and the answer is 409 `checkout.totals_changed` with the fresh `quote`. `next` is `pay` when the chosen method is online and the order awaits payment, otherwise `none`.
     *
     * @unauthenticated
     */
    #[Response(status: 201, type: 'array{data: array{order: '.OrderSummary::TYPE.', next: string}}')]
    public function place(PlaceOrderApiRequest $request, PlaceOrder $place): JsonResponse
    {
        $owner = $this->owners->fromRequest($request);
        $locale = app()->getLocale();
        $key = (string) $request->headers->get('Idempotency-Key');

        try {
            $review = $this->reviewFor($owner, $request, (string) $request->paymentMethod());
            $order = $place->handle(new PlaceOrderCommand(
                $this->actor($request), $owner, $owner->userId, $review->contactName, $review->contactPhone, $review->contactEmail, $review->address,
                $review->shippingCode, $review->paymentCode, $request->expectedTotal(), $key, $locale, OrderChannel::Api,
                phoneConfirmed: $this->proof->valid($request->phoneProof(), $review->contactPhone),
                acceptedPolicyReleases: $request->policyReleases(),
            ));
        } catch (CheckoutException $e) {
            throw $this->problem($e, $e->reason === 'totals_changed' ? ['quote' => $this->freshQuote($owner, $request)] : []);
        } catch (DomainException $e) {
            throw $this->addressProblem($e);
        }

        $online = $order->payment_method_code !== 'cod' && in_array($order->payment_status->value, ['unpaid', 'pending', 'failed'], true);

        return response()->json(['data' => ['order' => OrderSummary::make($order), 'next' => $online ? 'pay' : 'none']], 201);
    }

    /** @throws CheckoutException @throws DomainException */
    private function reviewFor(CartOwner $owner, CheckoutRequest $request, ?string $payment): CheckoutReview
    {
        $draft = $this->build->handle($owner, $request->details(), false, app()->getLocale());

        return $this->review->handle($owner, $owner->userId, $draft, app()->getLocale(), $request->shippingMethod(), $payment);
    }

    /** @return array<string, mixed>|null the quote as it is now, so the app can show the new total; null if it cannot be worked out */
    private function freshQuote(CartOwner $owner, CheckoutRequest $request): ?array
    {
        try {
            $review = $this->reviewFor($owner, $request, $request->paymentMethod());

            return CheckoutResource::make($review, $this->policies($owner, $review), app()->getLocale(), $this->carts);
        } catch (CheckoutException|DomainException) {
            return null;
        }
    }

    /** @return list<PolicyNotice> */
    private function policies(CartOwner $owner, CheckoutReview $review): array
    {
        return $this->policies->outstanding($owner->userId, 'checkout', $review->paymentCode, app()->getLocale());
    }

    /** @param  array<string, mixed>  $extra */
    private function problem(CheckoutException $e, array $extra = []): \Throwable
    {
        if ($e->reason === 'contact_required') { // a missing name or phone is a mistake in a field, not a refusal
            $field = $e->params['field'] === 'contact_name' ? 'contact.name' : 'contact.phone';

            return ValidationException::withMessages([$field => [__('orders::checkout.errors.contact_required')]]);
        }

        return CheckoutProblems::from($e, $extra);
    }

    private function addressProblem(DomainException $e): ApiProblem
    {
        $field = ['phone_invalid' => 'address.phone', 'location_mismatch' => 'address.upazila_id', 'postal_invalid' => 'address.postal_code'][$e->getMessage()] ?? 'address.address_line';

        throw ValidationException::withMessages([$field => [__('customers::account.addresses.errors.'.$e->getMessage(), ['max' => 10])]]);
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user();

        return $user === null ? Actor::guest('api') : new Actor(ActorType::Customer, (int) $user->getAuthIdentifier(), (string) ($user->public_id ?? ''));
    }
}
