<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

/**
 * What a gateway can do, declared by its adapter from the provider's saved documentation. Anything not shown in the documents is
 * false: a refund on a gateway that cannot do it becomes a manual refund record.
 */
final readonly class Capabilities
{
    public function __construct(
        public bool $refund = false,
        public bool $partialRefund = false,
        public bool $refundStatus = false,
        public bool $statusQuery = false,
        public bool $signedCallback = false,
        public bool $sandbox = false,
        public ?int $minAmountMinor = null,
        public ?int $maxAmountMinor = null,
    ) {}
}
