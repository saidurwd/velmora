<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

use App\Modules\Platform\DTOs\Money;

final readonly class RefundRequest
{
    public function __construct(public string $providerTxnId, public string $refundRef, public Money $amount, public string $reason, public ?string $reference = null) {}
}
