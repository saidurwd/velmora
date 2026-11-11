<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Inventory\Actions\ApplyStockImport;
use App\Modules\Inventory\Actions\PreviewStockImport;
use App\Modules\Inventory\Actions\RollbackStockImport;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Inventory\Http\Concerns\ActsAsStaff;
use App\Modules\Inventory\Http\Requests\Admin\StockImportRequest;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Platform\Support\CsvWriter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Stock files for staff: read one and see what it would do, apply it once, undo it with a compensating batch. All of it needs `inventory.adjust` (set on the routes). */
class StockImportController
{
    use ActsAsStaff;

    public function index(): View
    {
        return view('inventory::admin.imports.index', [
            'imports' => DB::table('stock_imports')->orderByDesc('id')->paginate(20),   // index (created_at, id)
            'locations' => StockLocation::query()->where('is_active', true)->orderBy('id')->get(['code', 'name']),
            'places' => DB::table('stock_locations')->pluck('name', 'id'),
            'max' => (int) config('inventory.imports.max_rows'),
        ]);
    }

    public function store(StockImportRequest $request, PreviewStockImport $preview): RedirectResponse
    {
        $file = $request->file('file');
        abort_if($file === null || $file->getRealPath() === false, 422);

        try {
            $id = $preview->handle($this->actor($request), $file->getRealPath(), $file->getClientOriginalName(), (string) $request->validated('location'), (string) $request->validated('mode'), (string) $request->validated('note'));
        } catch (StockOpsException $e) {
            return back()->withErrors(['import' => __('inventory::ops.errors.'.$e->reason, $e->params)])->withInput();
        }

        return redirect()->route('admin.inventory.imports.show', $id);
    }

    public function show(Request $request, string $import): View
    {
        $row = DB::table('stock_imports')->where('public_id', $import)->first() ?? abort(404);   // unique public_id
        $onlyErrors = $request->query('only') === 'errors';
        $lines = DB::table('stock_import_rows')->where('import_id', $row->id)->when($onlyErrors, fn ($q) => $q->where('status', 'error'))->orderBy('line')->paginate(100)->withQueryString();   // unique (import_id, line)

        return view('inventory::admin.imports.show', [
            'import' => $row, 'lines' => $lines, 'onlyErrors' => $onlyErrors, 'place' => (string) DB::table('stock_locations')->where('id', $row->location_id)->value('name'),
            'expires' => CarbonImmutable::parse((string) $row->created_at, 'UTC')->addHours(max(1, (int) config('inventory.imports.preview_hours'))),
            'summary' => [
                'ok' => DB::table('stock_import_rows')->where('import_id', $row->id)->where('status', 'ok')->count(),   // unique (import_id, line)
                'reverted' => DB::table('stock_import_rows')->where('import_id', $row->id)->where('rollback_state', 'reverted')->count(),
                'kept' => DB::table('stock_import_rows')->where('import_id', $row->id)->whereIn('rollback_state', ['kept_changed', 'kept_blocked'])->count(),
            ],
        ]);
    }

    public function apply(Request $request, ApplyStockImport $apply, string $import): RedirectResponse
    {
        try {
            $apply->handle($this->actor($request), $import);
        } catch (StockOpsException $e) {
            abort_if($e->reason === 'import_not_found', 404);

            return back()->withErrors(['import' => __('inventory::ops.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('inventory::imports.applied'));
    }

    public function rollback(Request $request, RollbackStockImport $rollback, string $import): RedirectResponse
    {
        try {
            $done = $rollback->handle($this->actor($request), $import);
        } catch (StockOpsException $e) {
            abort_if($e->reason === 'import_not_found', 404);

            return back()->withErrors(['import' => __('inventory::ops.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('inventory::imports.rolled_back', ['reverted' => $done['reverted'], 'kept' => $done['kept']]));
    }

    /** The rows that were refused, as a file to fix and send again. */
    public function errors(string $import): StreamedResponse
    {
        $row = DB::table('stock_imports')->where('public_id', $import)->first() ?? abort(404);   // unique public_id

        return response()->streamDownload(function () use ($row): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            CsvWriter::bom($out);
            CsvWriter::row($out, ['line', 'sku', 'quantity', 'problem']);
            foreach (DB::table('stock_import_rows')->where('import_id', $row->id)->where('status', 'error')->orderBy('line')->cursor() as $r) {   // unique (import_id, line)
                CsvWriter::row($out, [(string) $r->line, (string) $r->sku, (string) $r->raw_quantity, __('inventory::ops.errors.row_'.$r->error_code)]);
            }
            fclose($out);
        }, 'stock-file-problems-'.strtoupper(substr((string) $row->public_id, -8)).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
