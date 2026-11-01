<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Shipping\Http\Concerns\ActsAsStaff;
use App\Modules\Shipping\Models\Shipment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Printable papers for the warehouse. Neither shows a price. Printing a packing slip (it carries the customer's name, phone and address) is recorded. */
class DocumentController
{
    use ActsAsStaff;

    public const PICK_LIMIT = 200;

    public function packingSlip(Request $request, OrderDirectory $orders, OrderFulfilment $fulfilment, AuditLogger $audit, string $shipment): View
    {
        $parcel = Shipment::query()->where('public_id', $shipment)->with('items')->firstOrFail();
        $ref = $orders->references([$parcel->order_id])[$parcel->order_id] ?? abort(404);
        $order = $fulfilment->view($ref['public_id']) ?? abort(404);
        $labels = $orders->itemLabels(array_values(array_map('intval', $parcel->items->pluck('order_item_id')->all())), app()->getLocale());
        $audit->record('shipment.slip_printed', $parcel, [], [], $this->actor($request));

        return view('shipping::admin.documents.packing-slip', [
            'parcel' => $parcel, 'order' => $order, 'labels' => $labels, 'back' => route('admin.shipping.shipments.show', $parcel->public_id),
        ]);
    }

    /** Everything to take off the shelves for parcels that are made but not yet handed to the courier: one row per product, with which order needs how many. */
    public function pickList(OrderDirectory $orders): View
    {
        // served by shipments(status, updated_at) and the unique (shipment_id, order_item_id)
        $rows = DB::table('shipment_items as i')->join('shipments as s', 's.id', '=', 'i.shipment_id')->where('s.status', 'created')->orderBy('s.id')->limit(self::PICK_LIMIT)
            ->get(['i.order_item_id', 'i.quantity', 's.order_id', 's.public_id']);
        $labels = $orders->itemLabels(array_values(array_unique(array_map('intval', $rows->pluck('order_item_id')->all()))), app()->getLocale());
        $refs = $orders->references(array_values(array_unique(array_map('intval', $rows->pluck('order_id')->all()))));

        $products = [];
        foreach ($rows as $r) {
            $label = $labels[(int) $r->order_item_id] ?? ['name' => '', 'sku' => '#'.$r->order_item_id];
            $products[$label['sku']]['name'] = $label['name'];
            $products[$label['sku']]['quantity'] = ($products[$label['sku']]['quantity'] ?? 0) + (int) $r->quantity;
            $products[$label['sku']]['for'][] = ['order' => $refs[(int) $r->order_id]['number'] ?? '', 'parcel' => strtoupper(substr((string) $r->public_id, -8)), 'quantity' => (int) $r->quantity];
        }
        ksort($products);

        return view('shipping::admin.documents.pick-list', ['products' => $products, 'parcels' => $rows->pluck('public_id')->unique()->count(), 'capped' => $rows->count() >= self::PICK_LIMIT, 'back' => route('admin.shipping.shipments.index')]);
    }
}
