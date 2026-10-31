<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Payments\Actions\ImportSettlement;
use App\Modules\Payments\Actions\ResolveReconciliationException;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Http\Concerns\ActsAsStaff;
use App\Modules\Payments\Http\Requests\ImportSettlementRequest;
use App\Modules\Payments\Http\Requests\ResolveExceptionRequest;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\ReconciliationException;
use App\Modules\Payments\Models\SettlementImport;
use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReconciliationController
{
    use ActsAsStaff;

    public const PER_PAGE = 25;

    public function index(Request $request, OrderDirectory $orders): View
    {
        $filters = $request->validate(['status' => ['nullable', 'in:open,resolved,ignored,all'], 'type' => ['nullable', 'string', 'max:30']]);
        $status = $filters['status'] ?? 'open';

        $exceptions = ReconciliationException::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, string $t) => $q->where('type', $t))
            ->orderBy('id')->paginate(self::PER_PAGE)->withQueryString();

        return view('payments::admin.reconciliation.index', [
            'exceptions' => $exceptions, 'filters' => ['status' => $status, 'type' => $filters['type'] ?? ''],
            'orders' => $orders->references(array_values(array_filter($exceptions->pluck('order_id')->all()))),
            'imports' => SettlementImport::query()->latest('id')->limit(10)->get(),
            'providers' => PaymentMethod::query()->where('type', '!=', 'cod')->distinct()->orderBy('provider')->pluck('provider'),
            'can' => $request->user('admin')?->can('payments.reconcile') === true,
        ]);
    }

    public function import(ImportSettlementRequest $request, ImportSettlement $import, Clock $clock): RedirectResponse
    {
        $csv = (string) $request->file('file')?->get();
        if (! mb_check_encoding($csv, 'UTF-8')) {
            return back()->withErrors(['import' => __('payments::payments.errors.settlement_file_invalid', ['errors' => 'not UTF-8 text'])])->withInput();
        }

        $from = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $request->validated('from').' 00:00:00', 'Asia/Dhaka')?->utc();
        $to = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $request->validated('to').' 23:59:59', 'Asia/Dhaka')?->utc();
        abort_if($from === null || $to === null, 422);

        try {
            $result = $import->handle($this->actor($request), (string) $request->validated('provider'), $csv, $from, $to->greaterThan($clock->now()) ? $clock->now() : $to);
        } catch (PaymentException $e) {
            return back()->withErrors(['import' => __('payments::payments.errors.'.$e->reason, $e->params)])->withInput();
        }

        return back()->with('status', __('payments::admin.imported', ['rows' => $result->row_count, 'matched' => $result->matched_count, 'problems' => $result->problem_count]));
    }

    public function resolve(ResolveExceptionRequest $request, ResolveReconciliationException $resolve, string $exception): RedirectResponse
    {
        try {
            $resolve->handle($this->actor($request), $exception, (string) $request->validated('outcome'), (string) $request->validated('note'));
        } catch (PaymentException $e) {
            return back()->withErrors(['resolve' => __('payments::payments.errors.'.$e->reason, $e->params)])->withInput();
        }

        return back()->with('status', __('payments::admin.exception_closed'));
    }
}
