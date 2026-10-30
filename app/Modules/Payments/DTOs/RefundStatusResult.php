<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

/** `state`: 'refunded' (money returned), 'processing', 'cancelled'. */
final readonly class RefundStatusResult
{
    public function __construct(public string $state, public ?string $refundedOn = null) {}
}
