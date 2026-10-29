<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\CartRepository;
use App\Modules\Cart\Services\CartRules;
use App\Modules\Cart\Services\GuestCartToken;
use App\Modules\Platform\Support\Transactions;

/** On sign-in: the guest cart's lines join the customer's cart (quantities add up, capped), and the guest cart is deleted. */
final class MergeGuestCart
{
    public function __construct(private readonly CartRepository $carts) {}

    public function handle(int $userId, string $guestToken): void
    {
        Transactions::run(function () use ($userId, $guestToken): void {
            $guest = Cart::query()->where('guest_token_hash', GuestCartToken::hash($guestToken))->lockForUpdate()->first();
            if ($guest === null) {
                return;
            }

            $guestItems = CartItem::query()->where('cart_id', $guest->id)->get();
            if ($guestItems->isNotEmpty()) {
                $user = $this->carts->findOrCreate(CartOwner::user($userId));
                $user->newQuery()->whereKey($user->id)->lockForUpdate()->first();

                foreach ($guestItems as $g) {
                    $mine = CartItem::query()->where('cart_id', $user->id)->where('variant_id', $g->variant_id)->first();
                    if ($mine === null) {
                        if (CartItem::query()->where('cart_id', $user->id)->count() >= CartRules::MAX_LINES) {
                            continue;
                        }
                        CartItem::query()->create(['cart_id' => $user->id, 'variant_id' => $g->variant_id, 'quantity' => min($g->quantity, CartRules::MAX_PER_LINE), 'added_price_minor' => $g->added_price_minor]);
                    } else {
                        $mine->forceFill(['quantity' => min($mine->quantity + $g->quantity, CartRules::MAX_PER_LINE)])->save();
                    }
                }
                $this->carts->touch($user);
            }

            $guest->delete(); // its lines go with it (cascade)
        });
    }
}
