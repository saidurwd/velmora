<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Jobs;

use App\Modules\Bookings\Actions\AutoCompleteBookings;
use App\Modules\Bookings\Actions\SendBookingReminders;
use App\Modules\Bookings\Actions\SendReviewPrompts;
use App\Modules\Bookings\Actions\StartBookings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Every five minutes: start bookings whose time has come, complete the forgotten ones, remind, and ask how it went. Each step is safe to repeat. */
class RunBookingLifecycleJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(StartBookings $start, AutoCompleteBookings $complete, SendBookingReminders $reminders, SendReviewPrompts $reviews): void
    {
        $start->handle();
        $complete->handle();
        $reminders->handle();
        $reviews->handle();
    }
}
