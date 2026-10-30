<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

/** Which transaction to ask the gateway about. Whatever the browser or the notification claimed is only a hint for finding it. */
final readonly class VerificationRequest
{
    public function __construct(public string $merchantRef, public ?string $providerValidationId = null, public ?string $providerSession = null) {}
}
