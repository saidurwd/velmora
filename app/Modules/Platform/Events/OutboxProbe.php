<?php

declare(strict_types=1);

namespace App\Modules\Platform\Events;

use App\Modules\Platform\Outbox\DomainEvent;

/** Diagnostic event used by tests and `outbox:probe` to prove the pipeline end to end. Carries no business data. */
final class OutboxProbe extends DomainEvent
{
    public function __construct(public readonly string $token) {}

    public static function eventName(): string
    {
        return 'platform.outbox_probe';
    }

    public function toPayload(): array
    {
        return ['token' => $this->token];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((string) $payload['token']);
    }
}
