<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use App\Modules\Bookings\Models\ServicePackage;
use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Orders\Contracts\ServiceOrderLines;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\DTOs\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What staff see of bookings: a filtered list, one booking with its whole story and what each action would mean, and the calendar. It only reads. A fixed number
 * of queries however many bookings are on the page.
 *
 * @phpstan-import-type Terms from CancellationPolicies
 */
final class StaffBookings
{
    public const PER_PAGE = 25;

    public const STATUSES = ['held', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];

    public function __construct(
        private readonly ServiceOrderLines $lines, private readonly OrderDirectory $orders, private readonly VariantDirectory $variants, private readonly CancellationPolicies $policies,
        private readonly SlotSearch $search, private readonly Clock $clock,
    ) {}

    /**
     * @param  array{status?: string, from?: string, to?: string, resource?: string, sku?: string, order?: string}  $f
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(array $f): LengthAwarePaginator
    {
        $zone = (string) config('bookings.timezone');
        $q = DB::table('service_bookings as b')->join('service_resources as r', 'r.id', '=', 'b.resource_id')->join('service_packages as p', 'p.id', '=', 'b.package_id')->whereNotNull('b.order_item_id');

        if (in_array($f['status'] ?? '', self::STATUSES, true)) {
            $q->where('b.status', $f['status']);
        }
        if (($f['from'] ?? '') !== '' && $this->date($f['from']) !== null) {
            $q->where('b.starts_at', '>=', CarbonImmutable::parse($f['from'], $zone)->startOfDay()->utc());
        }
        if (($f['to'] ?? '') !== '' && $this->date($f['to']) !== null) {
            $q->where('b.starts_at', '<', CarbonImmutable::parse($f['to'], $zone)->addDay()->startOfDay()->utc());
        }
        if (($f['resource'] ?? '') !== '') {
            $q->where('r.public_id', $f['resource']);
        }
        if (($f['sku'] ?? '') !== '') {
            $variant = $this->variants->findBySku($f['sku']);
            $q->where('p.variant_id', $variant->id ?? 0);
        }
        if (($f['order'] ?? '') !== '') {
            $order = $this->orders->findByNumber($f['order']);
            $items = $order === null ? [] : array_map(fn ($l) => $l->orderItemId, $this->lines->linesFor($order['id']));
            $q->whereIn('b.order_item_id', $items === [] ? [0] : $items);
        }

        /** @var LengthAwarePaginator<int, \stdClass> $page */
        $page = $q->orderByDesc('b.starts_at')->orderByDesc('b.id')->paginate(self::PER_PAGE, ['b.*', 'r.name as resource_name', 'p.variant_id as variant_id']);   // index (status, starts_at)
        $rows = $page->getCollection();

        $byItem = [];
        foreach ($this->lines->linesForItems(array_values($rows->pluck('order_item_id')->map(fn ($v): int => (int) $v)->all())) as $l) {
            $byItem[$l->orderItemId] = $l;
        }
        $contacts = $this->orders->contacts(array_values(array_unique(array_map(fn ($l) => $l->orderId, $byItem))));
        $names = $this->variants->findMany(array_values(array_unique($rows->pluck('variant_id')->map(fn ($v): int => (int) $v)->all())));

        /** @var list<array<string, mixed>> $items */
        $items = $rows->map(function (\stdClass $b) use ($byItem, $contacts, $names): array {
            $line = $byItem[(int) $b->order_item_id] ?? null;
            $contact = $line === null ? null : ($contacts[$line->orderId] ?? null);

            return [
                'public_id' => (string) $b->public_id, 'status' => (string) $b->status, 'starts_at' => CarbonImmutable::parse((string) $b->starts_at, 'UTC'), 'ends_at' => CarbonImmutable::parse((string) $b->ends_at, 'UTC'),
                'service' => $names[(int) $b->variant_id]->productName ?? '', 'sku' => $names[(int) $b->variant_id]->sku ?? '', 'resource' => (string) $b->resource_name,
                'order_number' => $contact['number'] ?? '', 'order_public_id' => $contact['public_id'] ?? '', 'phone' => $contact === null ? '' : $this->mask($contact['phone']),
                'paid' => Money::of($line->paidMinor ?? 0),
            ];
        })->values()->all();

        return new LengthAwarePaginator($items, $page->total(), $page->perPage(), $page->currentPage(), ['path' => $page->path(), 'query' => $page->getOptions()['query'] ?? []]);
    }

    /** @return array<string, mixed>|null */
    public function detail(string $publicId, ?string $moveDate = null): ?array
    {
        $b = DB::table('service_bookings as b')->join('service_resources as r', 'r.id', '=', 'b.resource_id')->where('b.public_id', $publicId)->first(['b.*', 'r.name as resource_name', 'r.public_id as resource_public_id']);   // unique public_id
        if ($b === null || $b->order_item_id === null) {
            return null;
        }

        $package = ServicePackage::query()->findOrFail((int) $b->package_id);
        $line = $this->lines->lineFor((int) $b->order_item_id);
        $contact = $line === null ? null : ($this->orders->contacts([$line->orderId])[$line->orderId] ?? null);
        $variant = $this->variants->findMany([$package->variant_id])[$package->variant_id] ?? null;
        $now = $this->clock->now();
        $starts = CarbonImmutable::parse((string) $b->starts_at, 'UTC');
        $minutes = (int) floor(($starts->getTimestamp() - $now->getTimestamp()) / 60);
        $terms = $this->policies->termsOf($b);
        $paid = $line->paidMinor ?? 0;
        $confirmed = $b->status === 'confirmed';
        $started = $minutes <= 0;

        $cancellation = DB::table('booking_cancellations')->where('booking_id', $b->id)->first();   // unique booking_id
        $events = DB::table('booking_events')->where('booking_id', $b->id)->orderBy('id')->get(['event', 'actor_type', 'note', 'created_at']);   // index (booking_id, id)
        $notices = DB::table('booking_notices')->where('booking_id', $b->id)->orderBy('id')->get(['kind', 'channel', 'status', 'attempts', 'for_starts_at']);   // unique (booking_id, ...)

        $reassign = [];
        if ($confirmed) {
            foreach (DB::table('service_resources as r')->join('service_resource_links as l', 'l.resource_id', '=', 'r.id')->where('l.service_id', $package->service_id)->where('r.is_active', true)
                ->where('r.id', '!=', $b->resource_id)->orderBy('r.name')->get(['r.public_id', 'r.name']) as $r) {   // unique (service_id, resource_id)
                if ($this->search->offers($package, (string) $r->public_id, $starts, (int) $b->id)) {
                    $reassign[] = ['public_id' => (string) $r->public_id, 'name' => (string) $r->name];
                }
            }
        }

        return [
            'booking_id' => (int) $b->id, 'public_id' => (string) $b->public_id, 'status' => (string) $b->status, 'service' => $variant->productName ?? '', 'sku' => $variant->sku ?? '',
            'resource' => (string) $b->resource_name, 'starts_at' => $starts, 'ends_at' => CarbonImmutable::parse((string) $b->ends_at, 'UTC'), 'paid' => Money::of($paid),
            'order_number' => $contact['number'] ?? '', 'order_public_id' => $contact['public_id'] ?? '', 'customer_phone' => $contact === null ? '' : $this->mask($contact['phone']), 'customer_email' => $contact['email'] ?? null,
            'completed_by' => $b->completed_by, 'completed_at' => $b->completed_at === null ? null : CarbonImmutable::parse((string) $b->completed_at, 'UTC'), 'moved' => (int) $b->reschedule_count,
            'terms' => $terms, 'cancellation' => $cancellation, 'events' => $events, 'notices' => $notices,
            'can_cancel' => $confirmed, 'can_no_show' => in_array($b->status, ['confirmed', 'in_progress'], true) && $started, 'can_complete' => in_array($b->status, ['confirmed', 'in_progress'], true) && $started,
            'can_move' => $confirmed, 'reassign' => $reassign,
            'if_cancelled' => $confirmed ? $this->policies->outcome($terms, false, $minutes, $paid) : null,
            'if_no_show' => in_array($b->status, ['confirmed', 'in_progress'], true) ? $this->policies->outcome($terms, true, $minutes, $paid) : null,
            'minutes_before' => $minutes, 'package' => $package, 'resource_public_id' => (string) $b->resource_public_id,
            'move' => $confirmed ? $this->moveChoices($package, (string) $b->resource_public_id, (int) $b->id, $starts, $moveDate) : null,
        ];
    }

    /**
     * Bookings of a day or a week for the calendar, grouped by day and resource.
     *
     * @return array{days: list<string>, resources: list<array{public_id: string, name: string}>, cells: array<string, array<string, list<array<string, mixed>>>>, capped: bool}
     */
    public function calendar(string $start, int $days, ?string $resourcePublicId): array
    {
        $zone = (string) config('bookings.timezone');
        $first = CarbonImmutable::parse($start, $zone)->startOfDay();
        $dayList = [];
        for ($i = 0; $i < $days; $i++) {
            $dayList[] = $first->addDays($i)->format('Y-m-d');
        }

        $resources = DB::table('service_resources')->where('is_active', true)->when($resourcePublicId !== null && $resourcePublicId !== '', fn ($q) => $q->where('public_id', $resourcePublicId))->orderBy('name')->get(['id', 'public_id', 'name']);
        $limit = 1000;
        $rows = DB::table('service_bookings as b')->join('service_packages as p', 'p.id', '=', 'b.package_id')->whereIn('b.resource_id', $resources->pluck('id')->all())->whereNotNull('b.order_item_id')
            ->whereIn('b.status', ['held', 'confirmed', 'in_progress', 'completed', 'no_show'])->where('b.starts_at', '>=', $first->utc())->where('b.starts_at', '<', $first->addDays($days)->utc())
            ->orderBy('b.starts_at')->limit($limit + 1)->get(['b.public_id', 'b.resource_id', 'b.status', 'b.starts_at', 'b.ends_at', 'p.variant_id']);   // index (resource_id, starts_at)
        $capped = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $names = $this->variants->findMany(array_values(array_unique($rows->pluck('variant_id')->map(fn ($v): int => (int) $v)->all())));
        $publicOf = $resources->pluck('public_id', 'id');
        $cells = [];
        foreach ($rows as $b) {
            $starts = CarbonImmutable::parse((string) $b->starts_at, 'UTC');
            $cells[$starts->setTimezone($zone)->format('Y-m-d')][(string) $publicOf[(int) $b->resource_id]][] = [
                'public_id' => (string) $b->public_id, 'status' => (string) $b->status, 'starts_at' => $starts, 'ends_at' => CarbonImmutable::parse((string) $b->ends_at, 'UTC'), 'service' => $names[(int) $b->variant_id]->productName ?? '',
            ];
        }

        return ['days' => $dayList, 'resources' => array_values($resources->map(fn ($r): array => ['public_id' => (string) $r->public_id, 'name' => (string) $r->name])->all()), 'cells' => $cells, 'capped' => $capped];
    }

    /** @return array{date: string, slots: list<mixed>, problem: bool} */
    private function moveChoices(ServicePackage $package, string $resource, int $bookingId, CarbonImmutable $starts, ?string $date): array
    {
        $zone = (string) config('bookings.timezone');
        $day = $date !== null && $this->date($date) !== null ? $date : $starts->setTimezone($zone)->format('Y-m-d');

        return ['date' => $day, 'slots' => array_slice($this->search->forPackage($package, $day, $day, $resource, $bookingId), 0, 60), 'problem' => $date !== null && $this->date($date) === null];
    }

    private function date(string $value): ?string
    {
        try {
            $d = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $d !== null && $d->format('Y-m-d') === $value ? $value : null;
    }

    private function mask(string $phone): string
    {
        try {
            return PhoneNumber::parse($phone)->masked();
        } catch (Throwable) {
            return '';
        }
    }
}
