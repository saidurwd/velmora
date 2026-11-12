<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Controllers\Api;

use App\Modules\Cart\Actions\AcknowledgePriceChanges;
use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\Actions\ApplyCoupon;
use App\Modules\Cart\Actions\MergeGuestCart;
use App\Modules\Cart\Actions\RemoveCoupon;
use App\Modules\Cart\Actions\RemoveFromCart;
use App\Modules\Cart\Actions\SetCartQuantity;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Exceptions\CartException;
use App\Modules\Cart\Http\CartProblems;
use App\Modules\Cart\Http\Requests\Api\AddCartItemRequest;
use App\Modules\Cart\Http\Requests\Api\ApplyCouponRequest;
use App\Modules\Cart\Http\Requests\Api\SetQuantityRequest;
use App\Modules\Cart\Http\Resources\CartResource;
use App\Modules\Cart\Services\ApiCartOwners;
use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The cart. A signed-in customer's cart is their account's. A guest's cart belongs to whoever holds its `X-Cart-Token`: the first write answers with a
 * new token (in `data.token` and in the `X-Cart-Token` header) and the app sends it back on every cart and checkout call. Totals are always worked out
 * again from live prices and stock; the app never sends a price.
 */
class CartController
{
    public function __construct(private readonly ApiCartOwners $owners, private readonly CartReader $reader, private readonly PurchasableVariants $variants) {}

    /**
     * Show the cart
     *
     * Empty (and no token) for a guest without one. Lines carry `problem` (`unavailable`, `insufficient_stock`) and `price_changed_from` when a price moved since the item was added: show them, and call `/cart/acknowledge` once the person has seen the new price.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: '.CartResource::TYPE.'}')]
    public function show(Request $request): JsonResponse
    {
        return $this->answer($request, $this->owners->fromRequest($request));
    }

    /**
     * Add an item
     *
     * `variant` is the `variant.id` of a product. Adding an item already in the cart raises its quantity. Services are not added here: they are booked at a time.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 201, type: 'array{data: '.CartResource::TYPE.'}')]
    #[ResponseDoc(status: 200, type: 'array{data: '.CartResource::TYPE.'}')]
    public function add(AddCartItemRequest $request, AddToCart $add): JsonResponse
    {
        $variantId = $this->variants->idByPublicId((string) $request->validated('variant')) ?? throw new ApiProblem(ErrorCode::CartItemUnavailable, detail: __('cart::cart.errors.unavailable'));
        $owner = $this->owners->forWrite($request);

        try {
            $add->handle($owner, $variantId, $request->quantity());
        } catch (CartException $e) {
            throw CartProblems::from($e);
        }

        return $this->answer($request, $owner, 201);
    }

    /**
     * Set an item's quantity
     *
     * 0 takes the item out.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: '.CartResource::TYPE.'}')]
    public function setQuantity(SetQuantityRequest $request, string $variant, SetCartQuantity $set): JsonResponse
    {
        $variantId = $this->variants->idByPublicId($variant) ?? throw new ApiProblem(ErrorCode::CartItemNotFound, detail: __('cart::cart.errors.not_in_cart'));
        $owner = $this->owners->fromRequest($request);

        try {
            $set->handle($owner, $variantId, (int) $request->validated('quantity'));
        } catch (CartException $e) {
            throw CartProblems::from($e);
        }

        return $this->answer($request, $owner);
    }

    /**
     * Take an item out
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: '.CartResource::TYPE.'}')]
    public function remove(Request $request, string $variant, RemoveFromCart $remove): JsonResponse
    {
        $variantId = $this->variants->idByPublicId($variant) ?? throw new ApiProblem(ErrorCode::CartItemNotFound, detail: __('cart::cart.errors.not_in_cart'));
        $owner = $this->owners->fromRequest($request);
        $remove->handle($owner, $variantId);

        return $this->answer($request, $owner);
    }

    /**
     * Use a discount code
     *
     * A code that cannot be used is `coupon.invalid` with the reason in `detail` (unknown, expired, minimum spend, already used up).
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: '.CartResource::TYPE.'}')]
    public function applyCoupon(ApplyCouponRequest $request, ApplyCoupon $apply): JsonResponse
    {
        $owner = $this->owners->fromRequest($request);

        try {
            $apply->handle($owner, (string) $request->validated('code'), app()->getLocale());
        } catch (CartException $e) {
            throw CartProblems::from($e);
        }

        return $this->answer($request, $owner);
    }

    /**
     * Take a discount code off
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: '.CartResource::TYPE.'}')]
    public function removeCoupon(Request $request, string $code, RemoveCoupon $remove): JsonResponse
    {
        $owner = $this->owners->fromRequest($request);
        $remove->handle($owner, $code);

        return $this->answer($request, $owner);
    }

    /**
     * Accept the new prices
     *
     * Marks the price changes in the cart as seen, so they stop being flagged.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: '.CartResource::TYPE.'}')]
    public function acknowledge(Request $request, AcknowledgePriceChanges $ack): JsonResponse
    {
        $owner = $this->owners->fromRequest($request);
        $ack->handle($owner);

        return $this->answer($request, $owner);
    }

    /**
     * Bring a guest cart into my account
     *
     * Send right after signing in, with the `X-Cart-Token` the app held as a guest. The items join the account's cart under the cart's own rules; the guest cart is then gone. Returns the account's cart.
     */
    #[ResponseDoc(status: 200, type: 'array{data: '.CartResource::TYPE.'}')]
    public function merge(Request $request, MergeGuestCart $merge): JsonResponse
    {
        $token = $this->owners->token($request) ?? throw ValidationException::withMessages([ApiCartOwners::HEADER => [__('validation.required', ['attribute' => ApiCartOwners::HEADER])]]);
        $userId = (int) $request->user()?->getAuthIdentifier();
        $merge->handle($userId, $token);

        return $this->answer($request, CartOwner::user($userId));
    }

    private function answer(Request $request, CartOwner $owner, int $status = 200): JsonResponse
    {
        $token = $owner->userId === null ? $owner->guestToken : null;
        $view = $this->reader->view($owner, app()->getLocale());
        $response = response()->json(['data' => CartResource::make($view, $token)], $status);

        return $token === null ? $response : $response->header(ApiCartOwners::HEADER, $token);
    }
}
