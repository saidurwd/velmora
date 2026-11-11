<?php

declare(strict_types=1);

namespace App\Modules\Orders\Dashboard;

use App\Modules\Platform\Contracts\DashboardTile;
use App\Modules\Platform\DTOs\TileFigure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** A count of orders that link to the orders list with the same filter, so the number and the list always agree. */
abstract class OrderCountTile implements DashboardTile
{
    /** @return array<string, string> the list's query */
    abstract protected function filter(): array;

    abstract protected function apply(Builder $query): Builder;

    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function figure(): TileFigure
    {
        return new TileFigure($this->apply(DB::table('orders'))->count(), 'count', 'admin.orders.index', $this->filter());
    }
}
