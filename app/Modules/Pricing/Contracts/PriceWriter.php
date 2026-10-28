<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Pricing\Exceptions\PricingException;

/** Lets other modules (CSV import) set a regular price without reaching into Pricing's tables or Actions. */
interface PriceWriter
{
    /** @throws PricingException */
    public function setRegular(Actor $actor, int $variantId, int $amountMinor, ?int $compareAtMinor = null): void;
}
