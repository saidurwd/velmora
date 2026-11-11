<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Dashboard;

use App\Modules\Inventory\Contracts\StockReportFigures;
use App\Modules\Platform\Contracts\DashboardTile;
use App\Modules\Platform\DTOs\TileFigure;

/** Products at or below their low level or sold out, up to 100 (a "100+" means at least that many). Links to the low-stock report. */
final class LowStockTile implements DashboardTile
{
    private const CAP = 100;

    public function __construct(private readonly StockReportFigures $figures) {}

    public function code(): string
    {
        return 'low_stock';
    }

    public function label(): string
    {
        return 'inventory::dashboard.low_stock';
    }

    public function permissions(): array
    {
        return ['inventory.view'];
    }

    public function figure(): TileFigure
    {
        $n = count($this->figures->needingAttention(self::CAP));

        return new TileFigure($n, 'count', 'admin.inventory.reports.low', capped: $n >= self::CAP);
    }
}
