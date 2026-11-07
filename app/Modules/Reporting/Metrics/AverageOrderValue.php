<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Metrics;

use App\Modules\Reporting\DTOs\MetricInputs;

final class AverageOrderValue implements MetricDefinition
{
    public function code(): string
    {
        return 'average_order_value';
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
        return $in->orders === 0 ? 0 : intdiv(2 * $in->billedMinor + $in->orders, 2 * $in->orders);
    }
}
