<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Inventory\Actions\CancelStockCount;
use App\Modules\Inventory\Actions\DecideStockCount;
use App\Modules\Inventory\Actions\OpenStockCount;
use App\Modules\Inventory\Actions\RecordStockCount;
use App\Modules\Inventory\Actions\SubmitStockCount;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Inventory\Http\Concerns\ActsAsStaff;
use App\Modules\Inventory\Http\Requests\Admin\OpenCountRequest;
use App\Modules\Inventory\Http\Requests\Admin\ReasonRequest;
use App\Modules\Inventory\Http\Requests\Admin\RecordCountRequest;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockLocation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Stock-takes for staff: open one, type what was counted, hand it in, and a different person approves it (the Action enforces "a different person"). */
class CountController
{
    use ActsAsStaff;

    public function index(): View
    {
        return view('inventory::admin.counts.index', [
            'counts' => StockCount::query()->orderByDesc('id')->paginate(25), 'places' => StockLocation::query()->pluck('name', 'id'),
            'locations' => StockLocation::query()->where('is_active', true)->orderBy('id')->get(),
        ]);
    }

    public function open(OpenCountRequest $request, VariantDirectory $variants, OpenStockCount $open): RedirectResponse
    {
        $ids = null;
        $typed = preg_split('/[\s,]+/', trim((string) $request->validated('skus')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($typed !== []) {
            $ids = [];
            foreach (array_unique($typed) as $sku) {
                $variant = $variants->findBySku($sku);
                if ($variant === null) {
                    return back()->withErrors(['count' => __('inventory::ops.errors.variant_unknown').' ('.$sku.')'])->withInput();
                }
                $ids[] = $variant->id;
            }
        }

        try {
            $count = $open->handle($this->actor($request), (string) $request->validated('location'), $ids, $request->validated('note'));
        } catch (StockOpsException $e) {
            return back()->withErrors(['count' => __('inventory::ops.errors.'.$e->reason, $e->params)])->withInput();
        }

        return redirect()->route('admin.inventory.counts.show', $count->public_id)->with('status', __('inventory::screens.count_opened'));
    }

    public function show(Request $request, string $count, VariantDirectory $variants): View
    {
        $found = StockCount::query()->where('public_id', $count)->with(['lines' => fn ($q) => $q->orderBy('variant_id')])->firstOrFail();
        $user = $request->user('admin');

        return view('inventory::admin.counts.show', [
            'count' => $found, 'place' => StockLocation::query()->whereKey($found->location_id)->value('name'), 'variants' => $variants->findMany(array_values($found->lines->pluck('variant_id')->map(fn ($v): int => (int) $v)->all())),
            'canCount' => $user?->can('inventory.count') === true, 'canDecide' => $user?->can('inventory.adjust') === true && (int) $user->getAuthIdentifier() !== (int) $found->submitted_by,
        ]);
    }

    public function record(RecordCountRequest $request, string $count, RecordStockCount $record): RedirectResponse
    {
        try {
            foreach ((array) $request->validated('counted') as $variantId => $counted) {
                if ($counted !== null && $counted !== '') {
                    $record->handle($this->actor($request), $count, (int) $variantId, (int) $counted);
                }
            }
        } catch (StockOpsException $e) {
            return back()->withErrors(['count' => __('inventory::ops.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('inventory::screens.count_done.recorded'));
    }

    public function submit(Request $request, string $count, SubmitStockCount $submit): RedirectResponse
    {
        return $this->run(fn () => $submit->handle($this->actor($request), $count), 'submitted');
    }

    public function approve(Request $request, string $count, DecideStockCount $decide): RedirectResponse
    {
        return $this->run(fn () => $decide->approve($this->actor($request), $count), 'approved');
    }

    public function reject(ReasonRequest $request, string $count, DecideStockCount $decide): RedirectResponse
    {
        return $this->run(fn () => $decide->reject($this->actor($request), $count, (string) $request->validated('reason')), 'rejected');
    }

    public function cancel(Request $request, string $count, CancelStockCount $cancel): RedirectResponse
    {
        return $this->run(fn () => $cancel->handle($this->actor($request), $count), 'cancelled');
    }

    private function run(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockOpsException $e) {
            return back()->withErrors(['count' => __('inventory::ops.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('inventory::screens.count_done.'.$done));
    }
}
