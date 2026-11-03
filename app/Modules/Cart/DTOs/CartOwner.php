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

    /** A stable text for whose cart this is, for things that must be counted per visitor (never shown, and a guest's token is not recoverable from it). */
    public function key(): string
    {
        return $this->userId !== null ? 'u'.$this->userId : 'g'.substr(hash('sha256', (string) $this->guestToken), 0, 40);
    }

    public function isKnown(): bool
    {
        return $this->userId !== null || $this->guestToken !== null;
    }
}
