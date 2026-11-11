<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Dashboard;

final class OrdersTodayTile extends DayFigureTile
{
    public function code(): string
    {
        return 'orders_today';
    }

    public function label(): string
    {
        return 'reporting::dashboard.orders_today';
    }

    public function permissions(): array
    {
        return ['reports.view'];
    }

    protected function metric(): string
    {
        return 'orders';
    }

    protected function kind(): string
    {
        return 'count';
    }
}
