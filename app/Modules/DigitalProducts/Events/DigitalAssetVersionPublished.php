<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Events;

use App\Modules\Platform\Outbox\DomainEvent;

/** A new version of a digital file was put in. Written to the outbox in the same transaction as the upload, so it is announced once and only if the upload happened. */
final class DigitalAssetVersionPublished extends DomainEvent
{
    public function __construct(public readonly int $versionId, public readonly int $variantId, public readonly int $version) {}

    public static function eventName(): string
    {
        return 'digital.version_published';
    }

    public function toPayload(): array
    {
        return ['version_id' => $this->versionId, 'variant_id' => $this->variantId, 'version' => $this->version];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((int) $payload['version_id'], (int) $payload['variant_id'], (int) $payload['version']);
    }
}
