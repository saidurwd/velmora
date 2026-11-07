<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Metrics;

use App\Modules\Reporting\DTOs\MetricInputs;

final class Refunded implements MetricDefinition
{
    public function code(): string
    {
        return 'refunded';
    }

    public function kind(): string
    {
        return 'money';
    }

    public function finance(): bool
    {
        return true;
    }

    public function compute(MetricInputs $in): int
    {
        return $in->refundedMinor;
    }
}
