<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Providers;

use App\Modules\Bookings\Console\BookingProbeCommand;
use App\Modules\Platform\Support\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class BookingsServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Bookings';
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([BookingProbeCommand::class]);
        // per visitor, not shared with anything else
        RateLimiter::for('booking-hold', fn (Request $r) => Limit::perMinute(20)->by('booking-hold|'.($r->user('web')?->getAuthIdentifier() ?? $r->ip())));
    }
}
