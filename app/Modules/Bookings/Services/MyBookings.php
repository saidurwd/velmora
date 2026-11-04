<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Orders\Contracts\ServiceOrderLines;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a customer sees of their bookings: the list, and one booking with what they may do to it and what each choice would cost. It only reads. The customer's
 * orders are decided by the caller (Orders knows who owns them); this never looks at anybody else's booking. A fixed number of queries however many bookings.
 *
 * @phpstan-import-type Terms from CancellationPolicies
 */
final class MyBookings
{
    public function __construct(
        private readonly ServiceOrderLines $lines, private readonly OrderDirectory $orders, private readonly PurchasableVariants $variants,
        private readonly CancellationPolicies $policies, private readonly Clock $clock,
    ) {}

    /**
     * @param  list<int>  $orderIds
     * @return list<array<string, mixed>> soonest first for what is coming, then the past, newest first
     */
    public function forOrders(array $orderIds, string $locale): array
    {
        $lines = $this->lines->linesForOrders($orderIds);
        if ($lines === []) {
            return [];
        }

        $byItem = [];
        foreach ($lines as $l) {
            $byItem[$l->orderItemId] = $l;
        }
        $rows = DB::table('service_bookings as b')->join('service_resources as r', 'r.id', '=', 'b.resource_id')->join('service_packages as p', 'p.id', '=', 'b.package_id')->join('service_definitions as sd', 'sd.id', '=', 'p.service_id')
            ->whereIn('b.order_item_id', array_keys($byItem))->get(['b.*', 'r.name as resource_name', 'sd.location_type', 'sd.meeting_url']);   // unique order_item_id
        if ($rows->isEmpty()) {
            return [];
        }

        $names = $this->variants->details(array_values(array_unique(array_map(fn ($l) => $l->variantId, $lines))), $locale);
        $contacts = $this->orders->contacts(array_values(array_unique(array_map(fn ($l) => $l->orderId, $lines))));
        $ended = DB::table('booking_cancellations')->whereIn('booking_id', $rows->pluck('id')->all())->get()->keyBy('booking_id');   // unique booking_id
        $now = $this->clock->now();

        $out = [];
        foreach ($rows as $b) {
            $line = $byItem[(int) $b->order_item_id];
            $out[] = $this->row($b, $line->paidMinor, $names[$line->variantId]->productName ?? '', $contacts[$line->orderId]['number'] ?? '', $contacts[$line->orderId]['public_id'] ?? '', $ended->get($b->id), $now);
        }
        usort($out, function (array $a, array $b): int {
            $aUp = $a['upcoming'];
            $bUp = $b['upcoming'];

            return $aUp === $bUp ? ($aUp ? $a['starts_at'] <=> $b['starts_at'] : $b['starts_at'] <=> $a['starts_at']) : ($aUp ? -1 : 1);
        });

        return $out;
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<string, mixed>|null the booking, only if it belongs to one of these orders
     */
    public function find(array $orderIds, string $publicId, string $locale): ?array
    {
        foreach ($this->forOrders($orderIds, $locale) as $row) {
            if ($row['public_id'] === $publicId) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function row(\stdClass $b, int $paid, string $service, string $orderNumber, string $orderPublicId, ?\stdClass $ended, CarbonImmutable $now): array
    {
        $starts = CarbonImmutable::parse((string) $b->starts_at, 'UTC');
        $minutes = (int) floor(($starts->getTimestamp() - $now->getTimestamp()) / 60);
        $terms = $this->policies->termsOf($b);
        $confirmed = $b->status === 'confirmed';
        $movesLeft = max(0, $terms['max_reschedules'] - (int) $b->reschedule_count);

        return [
            'public_id' => (string) $b->public_id, 'status' => (string) $b->status, 'service' => $service, 'resource' => (string) $b->resource_name, 'starts_at' => $starts,
            'ends_at' => CarbonImmutable::parse((string) $b->ends_at, 'UTC'), 'paid' => Money::of($paid), 'order_number' => $orderNumber, 'order_public_id' => $orderPublicId,
            'upcoming' => in_array($b->status, ['held', 'confirmed', 'in_progress'], true),
            'can_cancel' => $confirmed && $minutes > 0,
            'can_move' => $confirmed && $minutes > 0 && $minutes >= $terms['reschedule_hours'] * 60 && $movesLeft > 0,
            'can_complete' => in_array($b->status, ['confirmed', 'in_progress'], true) && $minutes <= 0,
            'moves_left' => $movesLeft, 'move_hours' => $terms['reschedule_hours'], 'moved' => (int) $b->reschedule_count,
            'if_cancelled' => $confirmed && $minutes > 0 ? $this->policies->outcome($terms, false, $minutes, $paid) : null,
            'ended' => $ended === null ? null : ['kind' => (string) $ended->kind, 'fee' => Money::of((int) $ended->fee_minor), 'refund_due' => Money::of((int) $ended->refund_due_minor), 'by' => (string) $ended->by_type],
            'meeting_url' => $b->location_type === 'online' && in_array($b->status, ['confirmed', 'in_progress'], true) ? $b->meeting_url : null,   // only someone who has a confirmed booking sees where to join
            'booking_id' => (int) $b->id, 'package_id' => (int) $b->package_id, 'resource_id' => (int) $b->resource_id,
        ];
    }
}
