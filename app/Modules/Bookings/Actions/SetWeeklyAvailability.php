<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServiceResource;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Sets when a resource works in a normal week, replacing what was there. Times are Dhaka wall-clock (HH:MM); weekdays are 1 = Monday to 7 = Sunday. Two
 * windows on the same weekday must not overlap while both apply. Bookings already made are not touched. Who may do this (`bookings.manage`) is decided by the
 * caller.
 */
final class SetWeeklyAvailability
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{weekday: int, start: string, end: string, valid_from?: string|null, valid_to?: string|null}>  $windows
     *
     * @throws BookingException
     */
    public function handle(Actor $actor, ServiceResource $resource, array $windows): void
    {
        $rows = [];
        foreach ($windows as $w) {
            $weekday = (int) $w['weekday'];
            $start = self::time($w['start']);
            $end = self::time($w['end']);
            $from = self::date($w['valid_from'] ?? null);
            $to = self::date($w['valid_to'] ?? null);
            if ($weekday < 1 || $weekday > 7) {
                throw new BookingException('weekday_invalid');
            }
            if ($end <= $start) {
                throw new BookingException('window_end_before_start');
            }
            if ($from !== null && $to !== null && $from > $to) {
                throw new BookingException('validity_invalid');
            }
            $rows[] = ['resource_id' => $resource->id, 'weekday' => $weekday, 'start_time' => $start, 'end_time' => $end, 'valid_from' => $from, 'valid_to' => $to];
        }
        foreach ($rows as $i => $a) {
            foreach (array_slice($rows, $i + 1) as $b) {
                if ($a['weekday'] === $b['weekday'] && $a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time'] && self::validTogether($a, $b)) {
                    throw new BookingException('windows_overlap');
                }
            }
        }

        Transactions::run(function () use ($actor, $resource, $rows): void {
            $now = now();
            DB::table('availability_rules')->where('resource_id', $resource->id)->delete();
            foreach ($rows as $row) {
                DB::table('availability_rules')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
            }
            $this->audit->record('bookings.availability_set', $resource, [], ['windows' => count($rows)], $actor);
        });
    }

    /** "HH:MM" (or HH:MM:SS) to "HH:MM:SS". @throws BookingException */
    public static function time(string $value): string
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim($value), $m) !== 1) {
            throw new BookingException('time_invalid');
        }

        return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
    }

    /** @throws BookingException */
    public static function date(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            throw new BookingException('date_invalid');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function validTogether(array $a, array $b): bool
    {
        return ! ($a['valid_to'] !== null && $b['valid_from'] !== null && $a['valid_to'] < $b['valid_from'])
            && ! ($b['valid_to'] !== null && $a['valid_from'] !== null && $b['valid_to'] < $a['valid_from']);
    }
}
