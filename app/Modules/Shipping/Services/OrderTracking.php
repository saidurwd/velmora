<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Orders\Contracts\OrderFulfilment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a customer may see about where their order is: each parcel's stage in plain words, what is in it, the courier's tracking link, a timeline in our own
 * statuses, and what has not been sent yet. Deliberately NOT included: the address, phone numbers, the courier's own words and notes, who handled it, ids.
 * Everything is read in a fixed number of queries however many parcels there are.
 */
final class OrderTracking
{
    /** Shown to customers; a booking attempt or a word we do not understand is not part of the story. */
    private const HIDDEN_PROVIDER_STATUSES = ['booked', 'booking_failed'];

    public function __construct(private readonly OrderFulfilment $orders) {}

    /**
     * @return array{
     *     number: string, cancelled: bool, delivered: bool,
     *     parcels: list<array{label: int, status: string, courier: ?string, tracking_code: ?string, tracking_url: ?string, cod_minor: int, currency: string, attempts: int, items: list<array{name: string, quantity: int}>, events: list<array{status: string, at: CarbonImmutable}>}>,
     *     unsent: list<array{name: string, quantity: int}>
     * }|null
     */
    public function for(string $orderPublicId, string $locale): ?array
    {
        $order = $this->orders->view($orderPublicId);
        if ($order === null) {
            return null;
        }

        $names = [];
        foreach ($order->lines as $line) {
            $names[$line->orderItemId] = $line->names[$locale] ?? $line->names[(string) config('app.default_locale')] ?? $line->name;
        }

        $shipments = DB::table('shipments as s')->leftJoin('courier_accounts as a', 'a.id', '=', 's.courier_account_id')->where('s.order_id', $order->orderId)
            ->orderBy('s.id')->get(['s.id', 's.status', 's.courier', 's.tracking_code', 's.tracking_url', 's.cod_amount_minor', 's.currency', 's.attempts', 'a.label as account_label']);
        $ids = $shipments->pluck('id')->all();
        $items = $ids === [] ? collect() : DB::table('shipment_items')->whereIn('shipment_id', $ids)->orderBy('id')->get(['shipment_id', 'order_item_id', 'quantity'])->groupBy('shipment_id');
        $events = $ids === [] ? collect() : DB::table('shipment_events')->whereIn('shipment_id', $ids)->where('internal_status', '!=', 'unknown')->whereNotIn('provider_status', self::HIDDEN_PROVIDER_STATUSES)
            ->orderBy('occurred_at')->orderBy('id')->get(['shipment_id', 'internal_status', 'occurred_at'])->groupBy('shipment_id');

        $parcels = [];
        foreach ($shipments as $n => $s) {
            $parcels[] = [
                'label' => $n + 1,
                'status' => (string) $s->status,
                'courier' => $s->courier === 'manual' ? null : (string) ($s->account_label ?? $s->courier),
                'tracking_code' => $s->tracking_code === null ? null : (string) $s->tracking_code,
                'tracking_url' => $this->safeUrl($s->tracking_url),
                'cod_minor' => (int) $s->cod_amount_minor,
                'currency' => (string) $s->currency,
                'attempts' => (int) $s->attempts,
                'items' => array_values(($items[$s->id] ?? collect())->map(fn ($i): array => ['name' => $names[(int) $i->order_item_id] ?? '', 'quantity' => (int) $i->quantity])->all()),
                'events' => array_values(($events[$s->id] ?? collect())->map(fn ($e): array => ['status' => (string) $e->internal_status, 'at' => CarbonImmutable::parse((string) $e->occurred_at, 'UTC')])->all()),
            ];
        }

        $unsent = [];
        foreach ($order->lines as $line) {
            if ($line->productType === 'physical' && $line->remaining() > 0) {
                $unsent[] = ['name' => $names[$line->orderItemId] ?? $line->name, 'quantity' => $line->remaining()];
            }
        }

        return ['number' => $order->number, 'cancelled' => $order->notReadyReason === 'order_cancelled', 'delivered' => $order->deliveredAt !== null, 'parcels' => $parcels, 'unsent' => $unsent];
    }

    /** A courier's link is shown only if it is a plain https address (never a script, never a data address). */
    private function safeUrl(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';

        return preg_match('#^https://[^\s"\'<>]+$#i', $url) === 1 ? $url : null;
    }
}
