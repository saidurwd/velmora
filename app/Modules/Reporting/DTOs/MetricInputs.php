<?php

declare(strict_types=1);

namespace App\Modules\Reporting\DTOs;

/** The plain sums a metric is worked out from (over a period and a filter). A metric's formula lives in its own `MetricDefinition`, nowhere else. */
final readonly class MetricInputs
{
    public function __construct(
        public int $orders = 0,
        public int $grossMinor = 0,
        public int $discountsMinor = 0,
        public int $shippingMinor = 0,
        public int $codFeeMinor = 0,
        public int $taxMinor = 0,
        public int $billedMinor = 0,
        public int $cancelledOrders = 0,
        public int $cancelledBilledMinor = 0,
        public int $collectedMinor = 0,
        public int $refundedMinor = 0,
        public int $refundedLiveMinor = 0,
    ) {}
}
