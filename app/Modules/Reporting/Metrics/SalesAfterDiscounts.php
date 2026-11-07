<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Metrics;

use App\Modules\Reporting\DTOs\MetricInputs;

final class SalesAfterDiscounts implements MetricDefinition
{
    public function code(): string
    {
        return 'sales_after_discounts';
    }

    public function kind(): string
    {
        return 'money';
    }

    public function finance(): bool
    {
        return false;
    }

    public function compute(MetricInputs $in): int
    {
        return $in->grossMinor - $in->discountsMinor;
    }
}
