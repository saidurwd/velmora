<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServiceResource;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Support\Facades\DB;

/** Marks one day of one resource as closed, or as having different hours (Dhaka wall-clock). Replaces any exception already on that day. Who may do this (`bookings.manage`) is decided by the caller. */
final class SetAvailabilityException
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws BookingException */
    public function set(Actor $actor, ServiceResource $resource, string $date, bool $closed, ?string $start, ?string $end, ?string $note): void
    {
        $day = SetWeeklyAvailability::date($date) ?? throw new BookingException('date_invalid');
        $from = $closed ? null : SetWeeklyAvailability::time((string) $start);
        $to = $closed ? null : SetWeeklyAvailability::time((string) $end);
        if (! $closed && $to <= $from) {
            throw new BookingException('window_end_before_start');
        }
        $note = $note === null ? null : (mb_substr(trim(BanglaText::input($note)), 0, 190) ?: null);

        $now = now();
        DB::table('availability_exceptions')->updateOrInsert(['resource_id' => $resource->id, 'on_date' => $day], ['is_closed' => $closed, 'start_time' => $from, 'end_time' => $to, 'note' => $note, 'updated_at' => $now, 'created_at' => $now]);
        $this->audit->record('bookings.exception_set', $resource, [], ['date' => $day, 'closed' => $closed, 'start' => $from, 'end' => $to], $actor);
    }

    public function remove(Actor $actor, ServiceResource $resource, string $date): void
    {
        $day = SetWeeklyAvailability::date($date) ?? throw new BookingException('date_invalid');
        if (DB::table('availability_exceptions')->where('resource_id', $resource->id)->where('on_date', $day)->delete() > 0) {
            $this->audit->record('bookings.exception_removed', $resource, [], ['date' => $day], $actor);
        }
    }
}
