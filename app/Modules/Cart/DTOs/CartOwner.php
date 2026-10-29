<?php

declare(strict_types=1);

namespace App\Modules\Cart\DTOs;

/** Whose cart: a signed-in customer (user id) or a guest (random cookie token, kept hashed in the database). */
final readonly class CartOwner
{
    private function __construct(public ?int $userId, public ?string $guestToken) {}

    public static function user(int $userId): self
    {
        return new self($userId, null);
    }

    public static function guest(?string $token): self
    {
        return new self(null, $token === '' ? null : $token);
    }

    public function isKnown(): bool
    {
        return $this->userId !== null || $this->guestToken !== null;
    }
}
