<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Services\LowStockReport;
use App\Modules\Inventory\Services\StockAgingReport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ReportController
{
    public function low(Request $request, LowStockReport $report, VariantDirectory $variants): View
    {
        $places = StockLocation::query()->where('is_active', true)->where('type', '!=', 'quarantine')->orderBy('id')->get();
        $code = $places->contains('code', $request->query('place')) ? (string) $request->query('place') : null;
        $rows = $report->rows($code);

        return view('inventory::admin.reports.low', ['rows' => $rows, 'places' => $places, 'place' => $code, 'variants' => $variants->findMany(array_values(array_unique(array_column($rows, 'variant_id'))))]);
    }

    public function aging(StockAgingReport $report, VariantDirectory $variants): View
    {
        $data = $report->report();

        return view('inventory::admin.reports.aging', ['report' => $data, 'variants' => $variants->findMany(array_values(array_unique(array_column($data['oldest'], 'variant_id'))))]);
    }
}
