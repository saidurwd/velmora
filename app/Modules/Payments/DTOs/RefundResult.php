<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

/** `state`: 'accepted' (gateway took it, will pay out), 'processing' (already under way), or 'failed'. Anything but a final success is polled later. */
final readonly class RefundResult
{
    public function __construct(public string $state, public ?string $providerRefundId = null, public ?string $failureReason = null) {}
}
