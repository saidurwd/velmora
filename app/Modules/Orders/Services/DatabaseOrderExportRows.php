<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\OrderExportRows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseOrderExportRows implements OrderExportRows
{
    public function orders(CarbonImmutable $from, CarbonImmutable $to, ?string $channel, ?string $method, ?string $locale): iterable
    {
        // Index orders (placed_at): the period, in id order, a thousand at a time.
        $query = DB::table('orders')->where('placed_at', '>=', $this->at($from))->where('placed_at', '<', $this->at($to))
            ->when($channel !== null && $channel !== '', fn ($q) => $q->where('channel', $channel))
            ->when($method !== null && $method !== '', fn ($q) => $q->where('payment_method_code', $method))
            ->when($locale !== null && $locale !== '', fn ($q) => $q->where('locale', $locale));
        $items = fn (array $ids) => DB::table('order_items')->whereIn('order_id', $ids)->groupBy('order_id')->selectRaw('order_id, SUM(quantity) AS n')->pluck('n', 'order_id');   // index order_items (order_id)

        $buffer = [];
        foreach ($query->select(['id', 'order_number', 'placed_at', 'status', 'payment_status', 'fulfillment_status', 'channel', 'payment_method_code', 'locale', 'currency', 'grand_total_minor', 'contact_name', 'contact_phone'])->lazyById(1000) as $o) {
            $buffer[$o->id] = $o;
            if (count($buffer) < 1000) {
                continue;
            }
            yield from $this->flush($buffer, $items);
            $buffer = [];
        }
        yield from $this->flush($buffer, $items);
    }

    public function customers(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        // One group per person: the signed-in customer, or the guest's phone number. The period's orders are read once (index placed_at); the group is small.
        $rows = DB::table('orders')->where('placed_at', '>=', $this->at($from))->where('placed_at', '<', $this->at($to))
            ->groupByRaw("COALESCE(CONCAT('u', user_id), CONCAT('p', contact_phone))")->orderByRaw('MAX(placed_at) DESC')
            ->selectRaw("MAX(contact_name) AS name, MAX(contact_phone) AS phone, COUNT(*) AS orders, MIN(placed_at) AS first_order, MAX(placed_at) AS last_order, COALESCE(SUM(CASE WHEN currency = 'BDT' AND status <> 'cancelled' THEN grand_total_minor ELSE 0 END), 0) AS total");

        foreach ($rows->cursor() as $r) {
            yield [
                'name' => (string) $r->name, 'phone' => (string) $r->phone, 'orders' => (int) $r->orders,
                'first_order' => substr((string) $r->first_order, 0, 10), 'last_order' => substr((string) $r->last_order, 0, 10), 'total' => (int) $r->total,
            ];
        }
    }

    /**
     * @param  array<int, \stdClass>  $buffer
     * @return iterable<array<string, string|int|null>>
     */
    private function flush(array $buffer, callable $items): iterable
    {
        if ($buffer === []) {
            return;
        }
        $counts = $items(array_keys($buffer));
        foreach ($buffer as $o) {
            yield [
                'number' => (string) $o->order_number, 'placed' => substr((string) $o->placed_at, 0, 16), 'status' => (string) $o->status, 'payment_status' => (string) $o->payment_status,
                'fulfillment' => (string) $o->fulfillment_status, 'channel' => (string) $o->channel, 'payment_method' => (string) $o->payment_method_code, 'language' => (string) $o->locale,
                'currency' => (string) $o->currency, 'items' => (int) ($counts[$o->id] ?? 0), 'total' => (int) $o->grand_total_minor, 'name' => (string) $o->contact_name, 'phone' => (string) $o->contact_phone,
            ];
        }
    }

    private function at(CarbonImmutable $t): string
    {
        return $t->utc()->format('Y-m-d H:i:s.u');
    }
}
