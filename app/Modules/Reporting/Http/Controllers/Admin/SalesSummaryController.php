<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers\Admin;

use App\Modules\Payments\Contracts\PaymentMethodCatalog;
use App\Modules\Reporting\Http\Requests\ReportFilterRequest;
use App\Modules\Reporting\Metrics\MetricCatalogue;
use App\Modules\Reporting\Services\ReportDays;
use App\Modules\Reporting\Services\ReportReader;
use Illuminate\Contracts\View\View;

/** The sales summary: gross to net for a period, day by day. Looking = reports.view (set on the route); the money-in and money-out rows also need reports.finance.view. */
class SalesSummaryController
{
    public function index(ReportFilterRequest $request, ReportReader $reader, MetricCatalogue $catalogue, ReportDays $days, PaymentMethodCatalog $methods): View
    {
        $filter = $request->filter();
        $finance = $request->user('admin')?->can('reports.finance.view') === true;

        $perDay = [];
        foreach (array_reverse($days->range($filter->from, $filter->to)) as $day) {
            $perDay[$day] = null;
        }
        foreach ($reader->perDay($filter) as $day => $inputs) {
            $perDay[$day] = $catalogue->compute($inputs, $finance);
        }

        return view('reporting::admin.sales-summary', [
            'filter' => $filter,
            'finance' => $finance,
            'metrics' => array_values(array_filter($catalogue->all(), fn ($m): bool => $finance || ! $m->finance())),
            'totals' => $catalogue->compute($reader->inputs($filter), $finance),
            'perDay' => $perDay,
            'coverage' => $reader->coverage($filter),
            'methods' => array_map(fn (string $code): array => ['code' => $code, 'label' => $methods->label($code, app()->getLocale())], $reader->methods()),
            'today' => $days->today(),
        ]);
    }
}
