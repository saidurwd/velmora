<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Platform\Support\Transactions;

/** Removes an address; if it was the default, the most recently saved remaining one takes over. Past orders keep their own snapshot. */
final class DeleteAddress
{
    public function handle(CustomerAddress $address): void
    {
        Transactions::run(function () use ($address): void {
            $customerId = $address->customer_id;
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                CustomerAddress::query()->where('customer_id', $customerId)->orderByDesc('updated_at')->orderByDesc('id')->first()?->forceFill(['is_default' => true])->save();
            }
        });
    }
}
