<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Events;

use App\Modules\Platform\Outbox\DomainEvent;

/** Emitted (via the outbox) when a customer account is created. Customers creates the profile from it. */
final class UserRegistered extends DomainEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userPublicId,
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
    ) {}

    public static function eventName(): string
    {
        return 'identity.user_registered';
    }

    public function toPayload(): array
    {
        return ['user_id' => $this->userId, 'user_public_id' => $this->userPublicId, 'name' => $this->name, 'email' => $this->email, 'phone' => $this->phone];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((int) $payload['user_id'], (string) $payload['user_public_id'], $payload['name'] ?? null, $payload['email'] ?? null, $payload['phone'] ?? null);
    }
}
