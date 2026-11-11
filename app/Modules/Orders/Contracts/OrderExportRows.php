<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use Carbon\CarbonImmutable;

/** Read-only, for the Reporting module: the rows of the orders and customers files, produced one at a time so a big period never sits in memory. */
interface OrderExportRows
{
    /**
     * One row per order placed from `$from` (included) to `$to` (excluded), oldest first. Keys: number, placed, status, payment_status, fulfillment, channel, payment_method,
     * language, currency, items, total (minor units), name, phone.
     *
     * @return iterable<array<string, string|int|null>>
     */
    public function orders(CarbonImmutable $from, CarbonImmutable $to, ?string $channel, ?string $method, ?string $locale): iterable;

    /**
     * One row per customer who placed orders in the period (a signed-in customer is one person across phone numbers; a guest is told apart by phone number). Keys: name, phone,
     * orders, first_order, last_order, total (minor units; the customer's own currency is not mixed: only BDT orders are added up).
     *
     * @return iterable<array<string, string|int|null>>
     */
    public function customers(CarbonImmutable $from, CarbonImmutable $to): iterable;
}
