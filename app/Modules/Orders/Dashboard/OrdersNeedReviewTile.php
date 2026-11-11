<?php

declare(strict_types=1);

namespace App\Modules\Orders\Dashboard;

use Illuminate\Database\Query\Builder;

final class OrdersNeedReviewTile extends OrderCountTile
{
    public function code(): string
    {
        return 'orders_need_review';
    }

    public function label(): string
    {
        return 'orders::dashboard.orders_need_review';
    }

    protected function filter(): array
    {
        return ['review' => '1'];
    }

    protected function apply(Builder $query): Builder
    {
        return $query->where('needs_review', true);   // index (needs_review)
    }
}
