<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Platform\Support\Transactions;

final class SetDefaultAddress
{
    public function handle(CustomerAddress $address): void
    {
        Transactions::run(function () use ($address): void {
            CustomerAddress::query()->where('customer_id', $address->customer_id)->where('is_default', true)->whereKeyNot($address->id)->update(['is_default' => false]);
            $address->forceFill(['is_default' => true])->save();
        });
    }
}
