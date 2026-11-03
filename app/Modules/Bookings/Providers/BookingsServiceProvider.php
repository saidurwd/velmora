<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Providers;

use App\Modules\Bookings\Actions\ClaimBookingHold;
use App\Modules\Bookings\Console\BookingProbeCommand;
use App\Modules\Bookings\Contracts\BookingHolds;
use App\Modules\Bookings\Contracts\CancellationPolicyTexts;
use App\Modules\Bookings\Events\BookingEnded;
use App\Modules\Bookings\Events\BookingRescheduled;
use App\Modules\Bookings\Listeners\ConfirmBookingsForPaidOrder;
use App\Modules\Bookings\Listeners\EndBookingsOfRefundedOrder;
use App\Modules\Bookings\Listeners\ReleaseBookingsForCancelledOrder;
use App\Modules\Bookings\Services\DatabaseCancellationPolicyTexts;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderRefunded;
use App\Modules\Platform\Outbox\OutboxRegistry;
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
        $this->app->bind(CancellationPolicyTexts::class, DatabaseCancellationPolicyTexts::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([BookingProbeCommand::class]);
        Event::listen(OrderPaid::class, ConfirmBookingsForPaidOrder::class);
        Event::listen(OrderCancelled::class, ReleaseBookingsForCancelledOrder::class);
        Event::listen(OrderRefunded::class, EndBookingsOfRefundedOrder::class);
        $this->app->make(OutboxRegistry::class)->register(BookingEnded::class);
        $this->app->make(OutboxRegistry::class)->register(BookingRescheduled::class);
        RateLimiter::for('booking-manage', fn (Request $r) => Limit::perMinute(20)->by('booking-manage|'.($r->user('web')?->getAuthIdentifier() ?? $r->ip())));
        // per visitor, not shared with anything else
        RateLimiter::for('booking-hold', fn (Request $r) => Limit::perMinute(20)->by('booking-hold|'.($r->user('web')?->getAuthIdentifier() ?? $r->ip())));
    }
}
