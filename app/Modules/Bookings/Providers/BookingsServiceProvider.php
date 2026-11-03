<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Providers;

use App\Modules\Bookings\Actions\ClaimBookingHold;
use App\Modules\Bookings\Console\BookingProbeCommand;
use App\Modules\Bookings\Contracts\BookingHolds;
use App\Modules\Bookings\Listeners\ConfirmBookingsForPaidOrder;
use App\Modules\Bookings\Listeners\ReleaseBookingsForCancelledOrder;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Platform\Support\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

class BookingsServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Bookings';
    }

    public function register(): void
    {
        $this->app->bind(BookingHolds::class, ClaimBookingHold::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([BookingProbeCommand::class]);
        Event::listen(OrderPaid::class, ConfirmBookingsForPaidOrder::class);
        Event::listen(OrderCancelled::class, ReleaseBookingsForCancelledOrder::class);
        // per visitor, not shared with anything else
        RateLimiter::for('booking-hold', fn (Request $r) => Limit::perMinute(20)->by('booking-hold|'.($r->user('web')?->getAuthIdentifier() ?? $r->ip())));
    }
}
