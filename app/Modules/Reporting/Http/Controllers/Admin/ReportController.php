<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers\Admin;

use App\Modules\Payments\Contracts\PaymentMethodCatalog;
use App\Modules\Reporting\Http\Requests\ReportFilterRequest;
use App\Modules\Reporting\Reports\FileOnlyReport;
use App\Modules\Reporting\Reports\ReportCatalogue;
use App\Modules\Reporting\Services\ReportDays;
use App\Modules\Reporting\Services\ReportReader;
use App\Modules\Reporting\Services\ReportValues;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** The list of reports a person may open, and one report on screen (the first rows; the export has them all). Looking = reports.view plus what the report itself needs. */
class ReportController
{
    public function index(Request $request, ReportCatalogue $catalogue): View
    {
        $user = $request->user('admin');

        return view('reporting::admin.index', ['reports' => $catalogue->visibleFor($user), 'canExport' => $user?->can('reports.export') === true]);
    }

    public function show(ReportFilterRequest $request, ReportCatalogue $catalogue, ReportValues $values, ReportDays $days, ReportReader $reader, PaymentMethodCatalog $methods, string $code): View
    {
        $user = $request->user('admin');
        $report = $catalogue->find($code) ?? abort(404);
        abort_unless($catalogue->allowed($user, $report), 403);

        $filter = $values->normalise($report, $request->filter(), $days->today());
        $finance = $user?->can('reports.finance.view') === true;
        $limit = max(1, (int) config('reporting.screen_rows'));
        $rows = [];
        $more = false;
        $fileOnly = $report instanceof FileOnlyReport;
        foreach ($fileOnly ? [] : $report->rows($filter) as $row) {
            if (count($rows) >= $limit) {
                $more = true;
                break;
            }
            $rows[] = $row;
        }

        return view('reporting::admin.report', [
            'report' => $report, 'filter' => $filter, 'columns' => $values->visibleColumns($report, $finance), 'rows' => $rows, 'more' => $more, 'fileOnly' => $fileOnly, 'limit' => $limit, 'values' => $values,
            'canExport' => $user?->can('reports.export') === true, 'canPii' => $user?->can('customers.view_pii') === true, 'coverage' => in_array('period', $report->filters(), true) ? $reader->coverage($filter) : null,
            'methods' => array_map(fn (string $c): array => ['code' => $c, 'label' => $methods->label($c, app()->getLocale())], $reader->methods()), 'today' => $days->today(),
        ]);
    }
}
