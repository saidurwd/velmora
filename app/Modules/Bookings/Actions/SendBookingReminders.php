<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Services\BookingMessenger;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Reminds customers before a booking: a first reminder (24 hours before by default) and a last one (2 hours). Only for a booking that is still confirmed and has
 * not started. The long reminder is skipped for a booking made inside the short window, so nobody gets two at once. Each message is sent once per start time
 * (a moved booking is reminded for its new time); see BookingMessenger.
 */
final class SendBookingReminders
{
    public function __construct(private readonly BookingMessenger $messenger, private readonly Settings $settings, private readonly Clock $clock) {}

    /** @return int how many messages were sent now */
    public function handle(int $limit = 200): int
    {
        $now = $this->clock->now();
        $long = $this->settings->int('bookings.reminder_long_minutes', (int) config('bookings.reminder_long_minutes'));
        $short = $this->settings->int('bookings.reminder_short_minutes', (int) config('bookings.reminder_short_minutes'));
        $sent = 0;

        foreach (['reminder_short' => [0, $short], 'reminder_long' => [$short, $long]] as $kind => [$from, $until]) {
            if ($until <= $from) {
                continue;
            }
            $rows = DB::table('service_bookings')->where('status', 'confirmed')->whereNotNull('order_item_id')   // index (status, starts_at)
                ->where('starts_at', '>', $now->addMinutes($from))->where('starts_at', '<=', $now->addMinutes($until))->orderBy('starts_at')->limit($limit)->get();
            foreach ($rows as $booking) {
                $sent += $this->messenger->send($booking, $kind);
            }
        }

        return $sent;
    }
}
