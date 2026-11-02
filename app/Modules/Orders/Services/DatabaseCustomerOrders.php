<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\CustomerOrders;
use Illuminate\Support\Facades\DB;

final class DatabaseCustomerOrders implements CustomerOrders
{
    public function owns(int $userId, int $orderId): bool
    {
        return DB::table('orders')->where('id', $orderId)->where('user_id', $userId)->exists();
    }

    public function orderIdsOwnedBy(int $userId): array
    {
        // an account's orders, newest first (index on user_id; the same query "My orders" runs)
        return array_values(array_map('intval', DB::table('orders')->where('user_id', $userId)->orderByDesc('id')->limit(1000)->pluck('id')->all()));
    }
}
