<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Models\Cart;
use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

final class CartRepository
{
    public const GUEST_DAYS = 30;

    public const USER_DAYS = 90;

    public function __construct(private readonly Clock $clock) {}

    public function find(CartOwner $owner): ?Cart
    {
        return match (true) {
            $owner->userId !== null => Cart::query()->where('user_id', $owner->userId)->first(),
            $owner->guestToken !== null => Cart::query()->where('guest_token_hash', GuestCartToken::hash($owner->guestToken))->first(),
            default => null,
        };
    }

    public function findOrCreate(CartOwner $owner): Cart
    {
        $cart = $this->find($owner);
        if ($cart !== null) {
            return $cart;
        }

        try {
            return Cart::query()->create([
                'user_id' => $owner->userId,
                'guest_token_hash' => $owner->guestToken === null ? null : GuestCartToken::hash($owner->guestToken),
                'expires_at' => $this->expiry($owner),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->find($owner) ?? throw new \RuntimeException('Cart could not be created.'); // two tabs created it at once: use the winner
        }
    }

    public function touch(Cart $cart): void
    {
        $cart->forceFill(['expires_at' => $this->expiry($cart->user_id === null ? CartOwner::guest('x') : CartOwner::user($cart->user_id))])->save();
    }

    public function expiry(CartOwner $owner): CarbonImmutable
    {
        return $this->clock->now()->addDays($owner->userId === null ? self::GUEST_DAYS : self::USER_DAYS);
    }
}
