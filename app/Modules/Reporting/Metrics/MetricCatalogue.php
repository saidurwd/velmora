<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Metrics;

use App\Modules\Reporting\DTOs\MetricInputs;

/** Every metric, once, in the order staff see them. The only place a number is worked out from the sums. */
final class MetricCatalogue
{
    /** @return list<MetricDefinition> */
    public function all(): array
    {
        return [
            new OrdersCount, new GrossSales, new Discounts, new DeliveryCharges, new CodFees, new Tax, new BilledTotal, new AverageOrderValue,
            new CancelledOrders, new CancelledValue, new Collected, new Refunded, new NetSales,
        ];
    }

    /** @return array<string, int> code => value, leaving out finance metrics unless `$withFinance` */
    public function compute(MetricInputs $in, bool $withFinance): array
    {
        $out = [];
        foreach ($this->all() as $metric) {
            if ($metric->finance() && ! $withFinance) {
                continue;
            }
            $out[$metric->code()] = $metric->compute($in);
        }

        return $out;
    }
}
