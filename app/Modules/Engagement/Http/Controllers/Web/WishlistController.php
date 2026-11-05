<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers\Web;

use App\Modules\Engagement\Actions\AddToWishlist;
use App\Modules\Engagement\Actions\MoveWishlistItemToCart;
use App\Modules\Engagement\Actions\RemoveFromWishlist;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Http\Requests\VariantRequest;
use App\Modules\Engagement\Services\WishlistReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A signed-in customer's wishlist. The customer is always the one in the session; the address and the form never name a customer. */
class WishlistController
{
    private function userId(Request $request): int
    {
        return (int) $request->user('web')?->getAuthIdentifier();
    }

    public function index(Request $request, WishlistReader $reader): View
    {
        $userId = $this->userId($request);

        return view('engagement::account.wishlist', ['items' => $reader->items($userId, app()->getLocale()), 'alerts' => $reader->waitingAlerts($userId, app()->getLocale())]);
    }

    public function add(VariantRequest $request, AddToWishlist $add): RedirectResponse
    {
        try {
            $add->handle($this->userId($request), (string) $request->validated('variant'), app()->getLocale());
        } catch (EngagementException $e) {
            return back()->withErrors(['wishlist' => __('engagement::errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('engagement::wishlist.saved_status'));
    }

    public function remove(Request $request, RemoveFromWishlist $remove, string $variant): RedirectResponse
    {
        $remove->handle($this->userId($request), $variant);

        return back()->with('status', __('engagement::wishlist.removed_status'));
    }

    public function toCart(Request $request, MoveWishlistItemToCart $move, string $variant): RedirectResponse
    {
        try {
            $result = $move->handle($this->userId($request), $variant, app()->getLocale());
        } catch (EngagementException $e) {
            return back()->withErrors(['wishlist' => __('engagement::errors.'.$e->reason, $e->params)]);
        }
        if (! $result->wasAdded()) {
            return back()->withErrors(['wishlist' => __('cart::cart.errors.'.$result->reason, $result->params)]);
        }

        return redirect()->route('cart.show')->with('status', __('engagement::wishlist.moved_status'));
    }
}
