<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Metrics;

use App\Modules\Reporting\DTOs\MetricInputs;
use App\Modules\Reporting\Services\Ratio;

final class ReturnRate implements MetricDefinition
{
    public function code(): string
    {
        return 'return_rate';
    }

    public function kind(): string
    {
        return 'percent';
    }

    public function finance(): bool
    {
        return false;
    }

    public function compute(MetricInputs $in): int
    {
        return Ratio::basisPoints($in->returnedUnits, $in->unitsSold);
    }
}
