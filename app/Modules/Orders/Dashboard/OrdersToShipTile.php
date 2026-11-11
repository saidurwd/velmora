<?php

declare(strict_types=1);

namespace App\Modules\Orders\Dashboard;

use Illuminate\Database\Query\Builder;

final class OrdersToShipTile extends OrderCountTile
{
    public function code(): string
    {
        return 'orders_to_ship';
    }

    public function label(): string
    {
        return 'orders::dashboard.orders_to_ship';
    }

    protected function filter(): array
    {
        return ['status' => 'confirmed', 'fulfillment' => 'unfulfilled'];
    }

    protected function apply(Builder $query): Builder
    {
        return $query->where('status', 'confirmed')->where('fulfillment_status', 'unfulfilled');   // index (status, created_at), then a small filter
    }
}
