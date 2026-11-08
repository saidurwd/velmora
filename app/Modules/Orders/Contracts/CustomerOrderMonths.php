<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\DTOs\CustomerMonth;

/** Read-only, for the Reporting module (customer cohorts). Guests are not in it: without an account there is no safe way to know two orders are one person. */
interface CustomerOrderMonths
{
    /**
     * Every signed-in customer's live orders in `$currency`, added up per customer and month, in customer then month order. A month is a calendar month at `$utcOffsetSeconds`
     * from UTC (the shop's zone), taken from the time the order was placed. One at a time, so the whole history never sits in memory.
     *
     * @return iterable<CustomerMonth>
     */
    public function perCustomerMonth(string $currency, int $utcOffsetSeconds): iterable;
}
