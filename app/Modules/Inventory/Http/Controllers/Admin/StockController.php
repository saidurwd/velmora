<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Services\StockAdminReader;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockController
{
    /** What staff may record by hand. Shipments and reservations are written by the order flow, never typed in. */
    public const REASONS = ['initial', 'receipt', 'adjustment', 'count', 'write_off', 'return_restock'];

    public function index(Request $request, VariantDirectory $variants, StockAdminReader $reader): View
    {
        $term = trim((string) $request->query('q', ''));
        $found = $term === '' ? [] : $variants->search($term, 25);

        return view('inventory::admin.index', ['term' => $term, 'variants' => $found, 'totals' => $reader->totals(array_map(fn ($v) => $v->id, $found))]);
    }

    public function show(string $sku, VariantDirectory $variants, StockAdminReader $reader): View
    {
        $variant = $variants->findBySku($sku) ?? abort(404);

        return view('inventory::admin.show', [
            'variant' => $variant,
            'locations' => $reader->byLocation($variant->id),
            'movements' => $reader->movements($variant->id),
            'reasons' => self::REASONS,
        ]);
    }

    public function adjust(Request $request, string $sku, VariantDirectory $variants, StockAdminReader $reader, AdjustStock $adjust): RedirectResponse
    {
        $variant = $variants->findBySku($sku) ?? abort(404);

        $request->merge(['quantity' => BanglaText::numeric((string) $request->input('quantity', ''))]);
        $data = $request->validate([
            'location' => ['required', Rule::in($reader->activeLocationCodes())],
            'quantity' => ['required', 'regex:/^[+-]?\d{1,9}$/'],
            'reason' => ['required', Rule::in(self::REASONS)],
            'note' => [Rule::requiredIf(in_array($request->input('reason'), ['adjustment', 'count', 'write_off'], true)), 'nullable', 'string', 'max:255'],
        ]);

        if (! $variant->trackInventory) {
            return back()->withErrors(['quantity' => __('inventory::admin.not_tracked')]);
        }

        $delta = (int) $data['quantity'];
        if (in_array($data['reason'], ['initial', 'receipt', 'return_restock'], true) && $delta < 0) {
            return back()->withInput()->withErrors(['quantity' => __('inventory::admin.must_be_positive')]);
        }
        if ($data['reason'] === 'write_off') {
            $delta = -abs($delta);
        }

        try {
            $adjust->handle($this->actor($request), $variant->id, $data['location'], $delta, $data['reason'], ($data['note'] ?? '') === '' ? null : $data['note']);
        } catch (InventoryException $e) {
            return back()->withInput()->withErrors(['quantity' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.show', $sku)->with('status', __('inventory::admin.saved'));
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name);
    }
}
