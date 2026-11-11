<?php

declare(strict_types=1);

namespace App\Modules\Orders\Dashboard;

use Illuminate\Database\Query\Builder;

final class OrdersToConfirmTile extends OrderCountTile
{
    public function code(): string
    {
        return 'orders_to_confirm';
    }

    public function label(): string
    {
        return 'orders::dashboard.orders_to_confirm';
    }

    protected function filter(): array
    {
        return ['status' => 'pending'];
    }

    protected function apply(Builder $query): Builder
    {
        return $query->where('status', 'pending');   // index (status, created_at)
    }
}
