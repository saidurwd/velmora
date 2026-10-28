<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use Carbon\CarbonInterface;

/** The one place tax rates are read. Returns 0 when tax is disabled or no rate is in force. */
interface TaxRates
{
    /** @return int basis points in force for the tax class on that date (Asia/Dhaka calendar date) */
    public function rateBp(string $taxClassCode, ?CarbonInterface $on = null, string $jurisdiction = 'BD'): int;
}
