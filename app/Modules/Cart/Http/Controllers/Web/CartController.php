<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Controllers\Web;

use App\Modules\Cart\Actions\AcknowledgePriceChanges;
use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\Actions\RemoveFromCart;
use App\Modules\Cart\Actions\SetCartQuantity;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\Exceptions\CartException;
use App\Modules\Cart\Services\CartOwners;
use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartController
{
    public function __construct(private readonly CartOwners $owners, private readonly CartReader $reader, private readonly PurchasableVariants $variants) {}

    public function show(Request $request): View
    {
        return view('cart::show', ['cart' => $this->reader->view($this->owners->fromRequest($request), app()->getLocale())]);
    }

    public function add(Request $request, AddToCart $add): RedirectResponse
    {
        $data = $request->validate(['variant' => ['required', 'string', 'size:26'], 'quantity' => ['nullable', 'string', 'max:6']]);
        $variantId = $this->variants->idByPublicId($data['variant']);
        $quantity = $this->quantity($data['quantity'] ?? null);

        if ($variantId === null || $quantity === null) {
            return back()->withErrors(['cart' => __($variantId === null ? 'cart::cart.errors.unavailable' : 'cart::cart.errors.quantity_invalid')]);
        }

        try {
            $add->handle($this->owners->forWrite($request), $variantId, $quantity);
        } catch (CartException $e) {
            return back()->withErrors(['cart' => __('cart::cart.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('cart.show', ['locale' => app()->getLocale()])->with('status', __('cart::cart.added'));
    }

    public function update(Request $request, string $variant, SetCartQuantity $set): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'string', 'max:6']]);
        $variantId = $this->variants->idByPublicId($variant) ?? abort(404);
        $quantity = $this->quantity($data['quantity'], allowZero: true);

        if ($quantity === null) {
            return back()->withErrors(['cart' => __('cart::cart.errors.quantity_invalid')]);
        }

        try {
            $set->handle($this->owners->forWrite($request), $variantId, $quantity);
        } catch (CartException $e) {
            return back()->withErrors(['cart' => __('cart::cart.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('cart.show', ['locale' => app()->getLocale()])->with('status', __('cart::cart.updated'));
    }

    public function destroy(Request $request, string $variant, RemoveFromCart $remove): RedirectResponse
    {
        $remove->handle($this->owners->fromRequest($request), $this->variants->idByPublicId($variant) ?? abort(404));

        return redirect()->route('cart.show', ['locale' => app()->getLocale()])->with('status', __('cart::cart.removed'));
    }

    public function acknowledge(Request $request, AcknowledgePriceChanges $ack): RedirectResponse
    {
        $ack->handle($this->owners->fromRequest($request));

        return redirect()->route('cart.show', ['locale' => app()->getLocale()]);
    }

    /** Bangla digits welcome; whole numbers only. */
    private function quantity(?string $raw, bool $allowZero = false): ?int
    {
        $raw = BanglaText::numeric($raw ?? '1');
        if ($raw === '') {
            $raw = '1';
        }

        return preg_match('/^\d{1,4}$/', $raw) === 1 && ((int) $raw > 0 || $allowZero) ? (int) $raw : null;
    }
}
