<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers\Api;

use App\Modules\Engagement\Actions\AddToWishlist;
use App\Modules\Engagement\Actions\CancelStockAlert;
use App\Modules\Engagement\Actions\MoveWishlistItemToCart;
use App\Modules\Engagement\Actions\RemoveFromWishlist;
use App\Modules\Engagement\Actions\SubscribeStockAlert;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Http\EngagementProblems;
use App\Modules\Engagement\Http\Requests\Api\VariantApiRequest;
use App\Modules\Engagement\Services\WishlistReader;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use App\Modules\Platform\Http\Resources\ApiTypes;
use App\Modules\Platform\Http\Resources\MoneyData;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The customer's saved items, and "tell me when it is back". Always the signed-in customer's own; the app names the item, never the customer. */
class WishlistController
{
    private const TYPE = 'array{data: array{items: list<array{variant_id: string, name: string, slug: string, image: string|null, price: '.ApiTypes::MONEY.'|null, state: string, alert_set: bool, can_alert: bool}>, alerts: list<array{variant_id: string, name: string, slug: string, since: string}>}}';

    /**
     * My saved items
     *
     * `state` is `in_stock`, `out_of_stock` or `unavailable`. `can_alert` says "tell me when it is back" makes sense; `alerts` lists the ones already waiting.
     */
    #[ResponseDoc(status: 200, type: self::TYPE)]
    public function index(Request $request, WishlistReader $reader): JsonResponse
    {
        $userId = $this->userId($request);
        $locale = app()->getLocale();

        return response()->json(['data' => [
            'items' => array_map(fn (array $i): array => [
                'variant_id' => $i['variant'], 'name' => $i['name'], 'slug' => $i['slug'], 'image' => $i['image'], 'price' => $i['money'] === null ? null : MoneyData::from($i['money']),
                'state' => $i['state'], 'alert_set' => $i['alert'], 'can_alert' => $i['canAlert'],
            ], $reader->items($userId, $locale)),
            'alerts' => array_map(fn (array $a): array => ['variant_id' => $a['variant'], 'name' => $a['name'], 'slug' => $a['slug'], 'since' => $a['since']], $reader->waitingAlerts($userId, $locale)),
        ]]);
    }

    /**
     * Save an item
     *
     * `variant` is a product's `variant.id`. Saving it again changes nothing. Up to 200 items; services cannot be saved.
     *
     * @status 204
     */
    public function add(VariantApiRequest $request, AddToWishlist $add): Response
    {
        try {
            $add->handle($this->userId($request), (string) $request->validated('variant'), app()->getLocale());
        } catch (EngagementException $e) {
            throw EngagementProblems::from($e);
        }

        return response()->noContent();
    }

    /**
     * Remove an item
     *
     * @status 204
     */
    public function remove(Request $request, string $variant, RemoveFromWishlist $remove): Response
    {
        $remove->handle($this->userId($request), $variant);

        return response()->noContent();
    }

    /**
     * Move an item to the cart
     *
     * Puts it in the cart at the smallest quantity it allows, under the cart's own rules. If it cannot go in, the reason is in `detail`.
     *
     * @status 204
     */
    public function toCart(Request $request, string $variant, MoveWishlistItemToCart $move): Response
    {
        try {
            $result = $move->handle($this->userId($request), $variant, app()->getLocale());
        } catch (EngagementException $e) {
            throw EngagementProblems::from($e);
        }

        if (! $result->wasAdded()) {
            throw new ApiProblem(ErrorCode::CartItemUnavailable, ['params' => $result->params], detail: (string) __('cart::cart.errors.'.$result->reason, $result->params));
        }

        return response()->noContent();
    }

    /**
     * Tell me when it is back
     *
     * For an item that is out of stock. You are told once, by push, text or email, and can ask again.
     *
     * @status 204
     */
    public function alertOn(VariantApiRequest $request, SubscribeStockAlert $subscribe): Response
    {
        try {
            $subscribe->handle($this->userId($request), (string) $request->validated('variant'), app()->getLocale());
        } catch (EngagementException $e) {
            throw EngagementProblems::from($e);
        }

        return response()->noContent();
    }

    /**
     * Stop telling me
     *
     * @status 204
     */
    public function alertOff(Request $request, string $variant, CancelStockAlert $cancel): Response
    {
        $cancel->handle($this->userId($request), $variant);

        return response()->noContent();
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()?->getAuthIdentifier();
    }
}
