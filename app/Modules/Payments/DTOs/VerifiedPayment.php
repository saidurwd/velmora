<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Platform\DTOs\Money;

/**
 * The gateway's own answer, converted to our types. The amount is already in minor units (converted in the adapter with string
 * arithmetic, never floats). A payment is accepted as paid only when `status` is Valid and the merchant reference, amount and
 * currency also match our records.
 */
final readonly class VerifiedPayment
{
    /** @param  array<string, scalar|null>  $raw  the answer, with secrets and card numbers removed */
    public function __construct(
        public VerifiedStatus $status,
        public string $merchantRef,
        public ?Money $amount,
        public ?string $providerTxnId = null,
        public ?string $providerValidationId = null,
        public bool $riskFlag = false,
        public array $raw = [],
    ) {}
}
