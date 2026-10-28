<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

use App\Modules\Platform\DTOs\Money;

/** A priced line: net + tax = gross, always exactly (no paisa lost to rounding). */
final readonly class LineAmounts
{
    public function __construct(public Money $net, public Money $tax, public Money $gross) {}
}
