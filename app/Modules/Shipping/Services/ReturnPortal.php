<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Orders\Contracts\CustomerOrders;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the customer's returns pages show: what from one of their orders can still be sent back (and until when), and their own returns with the shop's answers.
 * Lines are numbered 1, 2, 3 in the order they were bought, so no internal id ever reaches the page. Always for the one customer asked about.
 */
final class ReturnPortal
{
    public function __construct(
        private readonly OrderFulfilment $orders,
        private readonly CustomerOrders $owned,
        private readonly OrderDirectory $directory,
        private readonly Settings $settings,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<array{position: int, name: string, left: int, until: CarbonImmutable|null, open: bool}>|null null when the order is not this customer's
     */
    public function lines(int $userId, string $orderPublicId, string $locale): ?array
    {
        $order = $this->orders->view($orderPublicId);
        if ($order === null || $order->ownerUserId !== $userId) {
            return null;
        }
        $window = max(1, $this->settings->int('shipping.return_window_days', (int) config('shipping.return_window_days')));
        $itemIds = array_map(fn ($l) => $l->orderItemId, $order->lines);

        $delivered = DB::table('shipment_items as si')->join('shipments as s', 's.id', '=', 'si.shipment_id')->where('s.order_id', $order->orderId)->where('s.status', 'delivered')->whereIn('si.order_item_id', $itemIds)
            ->groupBy('si.order_item_id')->selectRaw('si.order_item_id as item, SUM(si.quantity) as qty, MAX(s.delivered_at) as arrived')->get()->keyBy('item');   // index shipments.order_id
        $asked = DB::table('return_items as ri')->join('return_requests as rr', 'rr.id', '=', 'ri.return_id')->whereIn('ri.order_item_id', $itemIds)->whereIn('rr.status', ['requested', 'authorized'])
            ->groupBy('ri.order_item_id')->selectRaw('ri.order_item_id as item, SUM(ri.quantity) as qty')->get()->keyBy('item');

        $rows = [];
        $now = $this->clock->now();
        foreach ($order->lines as $i => $line) {
            if ($line->productType !== 'physical') {
                continue;
            }
            $d = $delivered->get($line->orderItemId);
            $left = $d === null ? 0 : max(0, (int) $d->qty - $line->returnedQty - (int) ($asked->get($line->orderItemId)->qty ?? 0));
            $until = $d === null || $d->arrived === null ? null : CarbonImmutable::parse((string) $d->arrived, 'UTC')->addDays($window);
            $rows[] = ['position' => $i + 1, 'name' => $line->names[$locale] ?? $line->name, 'left' => $left, 'until' => $until, 'open' => $left > 0 && $until !== null && $now->lessThanOrEqualTo($until)];
        }

        return $rows;
    }

    /** The order line (its internal id) behind a position on the page, or null. */
    public function itemAt(int $userId, string $orderPublicId, int $position): ?int
    {
        $order = $this->orders->view($orderPublicId);

        return $order === null || $order->ownerUserId !== $userId ? null : ($order->lines[$position - 1]->orderItemId ?? null);
    }

    /**
     * The customer's own returns, newest first, with their items.
     *
     * @return list<array{id: string, order: string, status: string, reason_code: string, reason: string|null, note: string|null, payer: string, created_at: string, items: array<int, array{name: string, quantity: int}>}>
     */
    public function mine(int $userId, string $locale): array
    {
        $orderIds = $this->owned->orderIdsOwnedBy($userId);
        if ($orderIds === []) {
            return [];
        }
        $returns = DB::table('return_requests')->whereIn('order_id', $orderIds)->orderByDesc('id')->limit(100)->get();   // index order_id
        $items = DB::table('return_items')->whereIn('return_id', $returns->pluck('id'))->get()->groupBy('return_id');
        $refs = $this->directory->references(array_values(array_unique(array_map(fn ($r): int => (int) $r->order_id, $returns->all()))));
        $names = $this->itemNames($orderIds, $locale);

        return array_values($returns->map(fn ($r) => [
            'id' => (string) $r->public_id, 'order' => $refs[(int) $r->order_id]['number'] ?? '', 'status' => (string) $r->status, 'reason_code' => (string) $r->reason_code, 'reason' => $r->reason === null ? null : (string) $r->reason,
            'note' => $r->decision_note === null ? null : (string) $r->decision_note, 'payer' => (string) $r->shipping_cost_payer, 'created_at' => (string) $r->created_at,
            'items' => ($items->get($r->id) ?? collect())->map(fn ($i) => ['name' => $names[(int) $i->order_item_id] ?? '', 'quantity' => (int) $i->quantity])->values()->all(),
        ])->all());
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, string> order item id => name in the customer's language
     */
    private function itemNames(array $orderIds, string $locale): array
    {
        $ids = array_values(DB::table('return_items as ri')->join('return_requests as rr', 'rr.id', '=', 'ri.return_id')->whereIn('rr.order_id', $orderIds)->pluck('ri.order_item_id')->map(fn ($v): int => (int) $v)->unique()->all());

        return array_map(fn (array $label): string => $label['name'], $this->directory->itemLabels($ids, $locale));
    }
}
