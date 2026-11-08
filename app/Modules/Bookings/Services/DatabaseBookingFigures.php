<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use App\Modules\Bookings\Contracts\BookingFigures;
use App\Modules\Bookings\DTOs\ResourceUtilisation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseBookingFigures implements BookingFigures
{
    public function __construct(private readonly OpeningHours $hours) {}

    public function utilisation(string $fromDate, string $toDate): array
    {
        $zone = (string) config('bookings.timezone');
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate, $zone);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $toDate, $zone);
        if ($from === null || $to === null || $to < $from) {
            return [];
        }
        $start = $from->utc()->format('Y-m-d H:i:s.u');
        $end = $to->addDay()->utc()->format('Y-m-d H:i:s.u');

        // Index service_bookings (resource_id, starts_at) / the period's bookings, then a small group by resource.
        $booked = DB::table('service_bookings')->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->where('status', '<>', 'held')
            ->groupBy('resource_id')
            ->selectRaw(
                'resource_id, COUNT(*) AS bookings, '
                ."COALESCE(SUM(status = 'completed'), 0) AS completed, COALESCE(SUM(status = 'no_show'), 0) AS no_shows, COALESCE(SUM(status = 'cancelled'), 0) AS cancelled, "
                ."COALESCE(SUM(CASE WHEN status IN ('confirmed','in_progress','completed') THEN TIMESTAMPDIFF(MINUTE, starts_at, ends_at) ELSE 0 END), 0) AS booked_minutes",
            )->get()->keyBy(fn ($r): int => (int) $r->resource_id);

        // Every active resource, and any resource that had a booking in the period even if it has since been switched off.
        $resources = DB::table('service_resources')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $booked->keys()->all()))->orderBy('name')->orderBy('id')->get(['id', 'name']);
        if ($resources->isEmpty()) {
            return [];
        }
        $ids = $resources->pluck('id')->map(fn ($v): int => (int) $v)->all();

        $rules = [];
        foreach (DB::table('availability_rules')->whereIn('resource_id', $ids)->get(['resource_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_to']) as $r) {   // index (resource_id, weekday)
            $rules[(int) $r->resource_id][(int) $r->weekday][] = $r;
        }
        $exceptions = [];
        foreach (DB::table('availability_exceptions')->whereIn('resource_id', $ids)->whereBetween('on_date', [$fromDate, $toDate])->get(['resource_id', 'on_date', 'is_closed', 'start_time', 'end_time']) as $e) {
            $exceptions[(int) $e->resource_id][(string) $e->on_date] = $e;
        }
        $holidays = array_flip(array_map('strval', DB::table('holidays')->whereBetween('on_date', [$fromDate, $toDate])->pluck('on_date')->all()));   // unique on_date

        $open = array_fill_keys($ids, 0);
        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            if (isset($holidays[$date])) {
                continue;
            }
            foreach ($ids as $id) {
                foreach ($this->hours->windows($id, $date, (int) $day->format('N'), $rules, $exceptions) as [$from2, $until2]) {
                    $open[$id] += $this->minutes($from2, $until2);
                }
            }
        }

        $out = [];
        foreach ($resources as $resource) {
            $b = $booked->get((int) $resource->id);
            $out[] = new ResourceUtilisation(
                (string) $resource->name, (int) ($b->bookings ?? 0), (int) ($b->completed ?? 0), (int) ($b->no_shows ?? 0), (int) ($b->cancelled ?? 0), (int) ($b->booked_minutes ?? 0), $open[(int) $resource->id],
            );
        }

        return $out;
    }

    /** Whole minutes between two wall-clock times of one day (HH:MM:SS). */
    private function minutes(string $from, string $until): int
    {
        $parse = fn (string $t): int => ((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2);

        return max(0, $parse($until) - $parse($from));
    }
}
