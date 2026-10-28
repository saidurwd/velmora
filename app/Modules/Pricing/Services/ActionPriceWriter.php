<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Contracts\PriceWriter;

final class ActionPriceWriter implements PriceWriter
{
    public function __construct(private readonly SetRegularPrice $set) {}

    public function setRegular(Actor $actor, int $variantId, int $amountMinor, ?int $compareAtMinor = null): void
    {
        $this->set->handle($actor, $variantId, Money::of($amountMinor), $compareAtMinor === null ? null : Money::of($compareAtMinor));
    }
}
