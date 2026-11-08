<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\DTOs\LineSalesRow;
use App\Modules\Orders\DTOs\OrderFact;
use App\Modules\Orders\DTOs\OrderTotals;
use App\Modules\Orders\DTOs\SalesRow;
use App\Modules\Orders\DTOs\TaxSalesRow;
use Carbon\CarbonImmutable;

/** Read-only totals for the Reporting module. It never sees an order's customer, address or lines. */
interface SalesFigures
{
    /**
     * Orders placed from `$from` (included) to `$to` (excluded), added up per channel, payment method, language and currency.
     *
     * @return list<SalesRow>
     */
    public function placedBetween(CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * The lines of orders placed from `$from` (included) to `$to` (excluded), added up per product variant, channel, payment method, language and currency.
     *
     * @return list<LineSalesRow>
     */
    public function linesPlacedBetween(CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * The tax on orders placed from `$from` (included) to `$to` (excluded), per kind of charge and rate.
     *
     * @return list<TaxSalesRow>
     */
    public function taxPlacedBetween(CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * @param  list<int>  $orderIds
     * @return array<int, OrderFact> keyed by order id (an unknown id is left out)
     */
    public function facts(array $orderIds): array;

    /**
     * @param  list<int>  $orderIds
     * @return array<int, OrderTotals> keyed by order id (an unknown id is left out)
     */
    public function totals(array $orderIds): array;
}
