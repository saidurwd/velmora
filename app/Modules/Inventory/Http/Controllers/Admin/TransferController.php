<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Inventory\Actions\CancelStockTransfer;
use App\Modules\Inventory\Actions\CreateStockTransfer;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Inventory\Http\Concerns\ActsAsStaff;
use App\Modules\Inventory\Http\Requests\Admin\CreateTransferRequest;
use App\Modules\Inventory\Http\Requests\Admin\ReasonRequest;
use App\Modules\Inventory\Http\Requests\Admin\ReceiveTransferRequest;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Moving stock between places for staff. The typed SKUs are turned into products here; the move itself is the Action's. */
class TransferController
{
    use ActsAsStaff;

    public const ROWS = 8;

    public function index(): View
    {
        $transfers = StockTransfer::query()->orderByDesc('id')->paginate(25);

        return view('inventory::admin.transfers.index', ['transfers' => $transfers, 'places' => StockLocation::query()->pluck('name', 'id')]);
    }

    public function create(): View
    {
        return view('inventory::admin.transfers.create', ['places' => StockLocation::query()->where('is_active', true)->orderBy('id')->get(), 'rows' => self::ROWS]);
    }

    public function store(CreateTransferRequest $request, VariantDirectory $variants, CreateStockTransfer $create): RedirectResponse
    {
        $lines = [];
        foreach ((array) $request->validated('lines') as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            $qty = (int) ($row['qty'] ?? 0);
            if ($sku === '' && $qty === 0) {
                continue;
            }
            $variant = $sku === '' ? null : $variants->findBySku($sku);
            if ($variant === null || $qty < 1) {
                return back()->withErrors(['transfer' => __($variant === null ? 'inventory::ops.errors.variant_unknown' : 'inventory::ops.errors.quantity_invalid').' ('.$sku.')'])->withInput();
            }
            $lines[$variant->id] = ($lines[$variant->id] ?? 0) + $qty;   // the same SKU typed twice is one line
        }

        try {
            $transfer = $create->handle($this->actor($request), (string) $request->validated('from'), (string) $request->validated('to'), $lines, $request->validated('note'));
        } catch (StockOpsException $e) {
            return back()->withErrors(['transfer' => __('inventory::ops.errors.'.$e->reason, $e->params)])->withInput();
        }

        return redirect()->route('admin.inventory.transfers.show', $transfer->public_id)->with('status', __('inventory::screens.transfer_made'));
    }

    public function show(Request $request, string $transfer, VariantDirectory $variants): View
    {
        $found = StockTransfer::query()->where('public_id', $transfer)->with('items')->firstOrFail();

        return view('inventory::admin.transfers.show', [
            'transfer' => $found, 'places' => StockLocation::query()->pluck('name', 'id'), 'variants' => $variants->findMany(array_values($found->items->pluck('variant_id')->map(fn ($v): int => (int) $v)->all())),
            'can' => $request->user('admin')?->can('inventory.transfer') === true,
        ]);
    }

    public function receive(ReceiveTransferRequest $request, string $transfer, ReceiveStockTransfer $receive): RedirectResponse
    {
        $received = [];
        foreach ((array) $request->validated('received') as $variantId => $quantity) {
            if ($quantity !== null && $quantity !== '') {
                $received[(int) $variantId] = (int) $quantity;
            }
        }

        return $this->run(fn () => $receive->handle($this->actor($request), $transfer, $received === [] ? null : $received), 'received');
    }

    public function cancel(ReasonRequest $request, string $transfer, CancelStockTransfer $cancel): RedirectResponse
    {
        return $this->run(fn () => $cancel->handle($this->actor($request), $transfer, (string) $request->validated('reason')), 'cancelled');
    }

    private function run(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockOpsException $e) {
            return back()->withErrors(['transfer' => __('inventory::ops.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('inventory::screens.transfer_done.'.$done));
    }
}
