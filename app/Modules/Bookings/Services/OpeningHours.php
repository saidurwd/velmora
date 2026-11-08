<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

/** When a resource works on a day: the day's exception if there is one (nothing when closed), else the weekly rules valid on that date. Shared by slot search and the booking figures, so they cannot disagree. */
final class OpeningHours
{
    /**
     * The working windows of one resource on one day: the day's exception if there is one (nothing when closed), else the weekly rules valid on that date.
     *
     * @param  array<int, array<int, list<\stdClass>>>  $rules
     * @param  array<int, array<string, \stdClass>>  $exceptions
     * @return list<array{0: string, 1: string}>
     */
    public function windows(int $resourceId, string $date, int $weekday, array $rules, array $exceptions): array
    {
        $exception = $exceptions[$resourceId][$date] ?? null;
        if ($exception !== null) {
            return (bool) $exception->is_closed ? [] : [[(string) $exception->start_time, (string) $exception->end_time]];
        }

        $out = [];
        foreach ($rules[$resourceId][$weekday] ?? [] as $r) {
            if (($r->valid_from === null || (string) $r->valid_from <= $date) && ($r->valid_to === null || (string) $r->valid_to >= $date)) {
                $out[] = [(string) $r->start_time, (string) $r->end_time];
            }
        }
        usort($out, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $out;
    }
}
