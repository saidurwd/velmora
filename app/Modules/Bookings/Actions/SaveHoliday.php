<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Support\Facades\DB;

/** A day the whole shop is closed to bookings (Eid, a national holiday). Who may do this (`bookings.manage`) is decided by the caller. */
final class SaveHoliday
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws BookingException */
    public function set(Actor $actor, string $date, string $name): void
    {
        $day = SetWeeklyAvailability::date($date) ?? throw new BookingException('date_invalid');
        $name = mb_substr(trim(BanglaText::input($name)), 0, 120);
        if ($name === '') {
            throw new BookingException('name_required');
        }

        $now = now();
        DB::table('holidays')->updateOrInsert(['on_date' => $day], ['name' => $name, 'updated_at' => $now, 'created_at' => $now]);
        $this->audit->record('bookings.holiday_set', 'holiday', [], ['date' => $day, 'name' => $name], $actor, $day);
    }

    public function remove(Actor $actor, string $date): void
    {
        $day = SetWeeklyAvailability::date($date) ?? throw new BookingException('date_invalid');
        if (DB::table('holidays')->where('on_date', $day)->delete() > 0) {
            $this->audit->record('bookings.holiday_removed', 'holiday', [], ['date' => $day], $actor, $day);
        }
    }
}
