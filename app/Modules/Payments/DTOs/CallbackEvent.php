<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

/**
 * A notification as it arrived, with the fields we need to find the payment. Nothing in it is trusted: it only says WHICH
 * transaction to verify.
 */
final readonly class CallbackEvent
{
    /** @param  array<string, scalar|null>  $payload  the raw fields, for storage (redacted before display) */
    public function __construct(
        public string $providerEventId,
        public ?string $merchantRef,
        public ?string $claimedStatus,
        public ?string $providerValidationId,
        public array $payload,
    ) {}
}
