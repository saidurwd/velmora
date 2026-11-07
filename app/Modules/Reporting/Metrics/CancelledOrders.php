<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Metrics;

use App\Modules\Reporting\DTOs\MetricInputs;

final class CancelledOrders implements MetricDefinition
{
    public function code(): string
    {
        return 'cancelled_orders';
    }

    public function kind(): string
    {
        return 'count';
    }

    public function finance(): bool
    {
        return false;
    }

    public function compute(MetricInputs $in): int
    {
        return $in->cancelledOrders;
    }
}
