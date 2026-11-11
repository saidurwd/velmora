<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Dashboard;

final class NetSalesTodayTile extends DayFigureTile
{
    public function code(): string
    {
        return 'net_sales_today';
    }

    public function label(): string
    {
        return 'reporting::dashboard.net_sales_today';
    }

    public function permissions(): array
    {
        return ['reports.view', 'reports.finance.view'];
    }

    protected function metric(): string
    {
        return 'net_sales';
    }

    protected function kind(): string
    {
        return 'money';
    }
}
