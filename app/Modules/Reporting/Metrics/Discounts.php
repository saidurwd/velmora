<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Metrics;

use App\Modules\Reporting\DTOs\MetricInputs;

final class Discounts implements MetricDefinition
{
    public function code(): string
    {
        return 'discounts';
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
        return $in->discountsMinor;
    }
}
