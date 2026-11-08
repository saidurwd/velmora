<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use App\Modules\Bookings\DTOs\Slot;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServiceDefinition;
use App\Modules\Bookings\Models\ServicePackage;
use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Settings;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Which times a customer can book. Read only: it changes nothing and a slot it shows is only a proposal until the hold is made (the database decides then).
 *
 * A slot is a start time on one resource where the service itself fits inside a working window, and the whole blocked time (the service plus the buffers
 * before and after) touches no held, confirmed or running booking of that resource. A hold that has run out counts as free. Working windows come from the
 * weekly timetable, replaced by an exception for that day (closed, or other hours), and no day is offered on a shop holiday. Nothing sooner than the lead
 * time from now, nothing further ahead than the horizon. Everything is worked out in Dhaka wall-clock time and returned in UTC.
 *
 * The number of queries is fixed however many days and resources are searched.
 */
final class SlotSearch
{
    public function __construct(private readonly VariantDirectory $variants, private readonly Clock $clock, private readonly Settings $settings) {}

    /**
     * @return list<Slot> earliest first, then by resource; at most the configured maximum
     *
     * @throws BookingException variant_unknown | package_unavailable | range_invalid | range_too_long
     */
    public function forVariant(string $variantSku, string $fromDate, string $toDate, ?string $resourcePublicId = null): array
    {
        $variant = $this->variants->findBySku($variantSku) ?? throw new BookingException('variant_unknown');
        $package = ServicePackage::query()->where('variant_id', $variant->id)->first() ?? throw new BookingException('package_unavailable');

        return $this->forPackage($package, $fromDate, $toDate, $resourcePublicId);
    }

    /**
     * Whether this exact start is one the search would offer on this resource right now. Used to refuse a made-up time before anything is held.
     */
    public function offers(ServicePackage $package, string $resourcePublicId, CarbonImmutable $start, ?int $ignoreBookingId = null): bool
    {
        $day = $start->setTimezone((string) config('bookings.timezone'))->format('Y-m-d');

        foreach ($this->forPackage($package, $day, $day, $resourcePublicId, $ignoreBookingId) as $slot) {
            if ($slot->startsAt->equalTo($start->utc())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Slot>
     *
     * @throws BookingException range_invalid | range_too_long
     */
    public function forPackage(ServicePackage $package, string $fromDate, string $toDate, ?string $resourcePublicId = null, ?int $ignoreBookingId = null): array
    {
        $zone = (string) config('bookings.timezone');
        $from = $this->day($fromDate, $zone);
        $to = $this->day($toDate, $zone);
        if ($to < $from) {
            throw new BookingException('range_invalid');
        }
        $days = (int) $from->diffInDays($to) + 1;
        if ($days > (int) config('bookings.max_search_days')) {
            throw new BookingException('range_too_long', ['days' => (int) config('bookings.max_search_days')]);
        }

        $service = ServiceDefinition::query()->find($package->service_id);
        if ($service === null || ! $service->is_active || ! $package->is_active) {
            return [];
        }

        $now = $this->clock->now();
        $earliest = $now->addMinutes($service->lead_time_min);
        $latest = $now->addDays($service->max_horizon_days);
        $rangeStart = $from->utc();                       // the start of the first local day, in UTC
        $rangeEnd = $to->addDay()->utc();                 // the start of the day after the last one
        if ($rangeEnd <= $earliest || $rangeStart > $latest) {
            return [];
        }

        $resources = DB::table('service_resources as r')->join('service_resource_links as l', 'l.resource_id', '=', 'r.id')->where('l.service_id', $service->id)->where('r.is_active', true)
            ->when($resourcePublicId !== null, fn ($q) => $q->where('r.public_id', $resourcePublicId))->orderBy('r.id')->get(['r.id', 'r.public_id', 'r.name']);   // index service_resource_links(service_id, resource_id)
        if ($resources->isEmpty()) {
            return [];
        }
        $ids = $resources->pluck('id')->map(fn ($v): int => (int) $v)->all();
        $firstDay = $from->format('Y-m-d');
        $lastDay = $to->format('Y-m-d');

        $rules = [];
        foreach (DB::table('availability_rules')->whereIn('resource_id', $ids)->get(['resource_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_to']) as $r) {   // index (resource_id, weekday)
            $rules[(int) $r->resource_id][(int) $r->weekday][] = $r;
        }
        $exceptions = [];
        foreach (DB::table('availability_exceptions')->whereIn('resource_id', $ids)->whereBetween('on_date', [$firstDay, $lastDay])->get(['resource_id', 'on_date', 'is_closed', 'start_time', 'end_time']) as $e) {   // unique (resource_id, on_date)
            $exceptions[(int) $e->resource_id][(string) $e->on_date] = $e;
        }
        $holidays = array_flip(array_map('strval', DB::table('holidays')->whereBetween('on_date', [$firstDay, $lastDay])->pluck('on_date')->all()));   // unique on_date

        $taken = [];   // resource id => list of [blocked from, blocked until] as UTC timestamps in microseconds precision strings
        foreach (DB::table('service_bookings')->when($ignoreBookingId !== null, fn ($q) => $q->where('id', '!=', $ignoreBookingId))->whereIn('resource_id', $ids)->where('blocks_until', '>', $rangeStart->subDay())->where('blocks_from', '<', $rangeEnd->addDay())
            ->where(fn ($q) => $q->whereIn('status', ['confirmed', 'in_progress'])->orWhere(fn ($h) => $h->where('status', 'held')->where('hold_expires_at', '>', $now)))   // index (resource_id, starts_at)
            ->get(['resource_id', 'blocks_from', 'blocks_until']) as $b) {
            $taken[(int) $b->resource_id][] = [CarbonImmutable::parse((string) $b->blocks_from, 'UTC'), CarbonImmutable::parse((string) $b->blocks_until, 'UTC')];
        }

        $step = max(5, $this->settings->int('bookings.slot_step_minutes', (int) config('bookings.slot_step_minutes')));
        $max = (int) config('bookings.max_slots');
        $slots = [];
        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            if (isset($holidays[$date])) {
                continue;
            }
            foreach ($resources as $resource) {
                foreach ($this->windows((int) $resource->id, $date, (int) $day->format('N'), $rules, $exceptions) as [$open, $close]) {
                    $windowStart = $this->local($date, $open, $zone);
                    $windowEnd = $this->local($date, $close, $zone);
                    for ($start = $windowStart; $start->addMinutes($package->duration_min) <= $windowEnd; $start = $start->addMinutes($step)) {
                        $end = $start->addMinutes($package->duration_min);
                        if ($start < $earliest || $start > $latest) {
                            continue;
                        }
                        $blockFrom = $start->subMinutes($package->buffer_before_min);
                        $blockUntil = $end->addMinutes($package->buffer_after_min);
                        foreach ($taken[(int) $resource->id] ?? [] as [$from2, $until2]) {
                            if ($blockFrom < $until2 && $blockUntil > $from2) {
                                continue 2;
                            }
                        }
                        $slots[] = new Slot((string) $resource->public_id, (string) $resource->name, $start->utc(), $end->utc());
                    }
                }
            }
        }

        usort($slots, fn (Slot $a, Slot $b): int => [$a->startsAt->getTimestamp(), $a->resourceName] <=> [$b->startsAt->getTimestamp(), $b->resourceName]);

        return array_slice($slots, 0, $max);
    }

    /**
     * The working windows of one resource on one day (see {@see OpeningHours}).
     *
     * @param  array<int, array<int, list<\stdClass>>>  $rules
     * @param  array<int, array<string, \stdClass>>  $exceptions
     * @return list<array{0: string, 1: string}>
     */
    private function windows(int $resourceId, string $date, int $weekday, array $rules, array $exceptions): array
    {
        return (new OpeningHours)->windows($resourceId, $date, $weekday, $rules, $exceptions);
    }

    /** The start of a local day, as a Carbon in the shop's zone. @throws BookingException */
    private function day(string $date, string $zone): CarbonImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new BookingException('range_invalid');
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $date, $zone) ?: throw new BookingException('range_invalid');
    }

    /** A wall-clock time on a local date, as a Carbon in the shop's zone. */
    private function local(string $date, string $time, string $zone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' '.$time, $zone) ?: throw new BookingException('time_invalid');
    }
}
