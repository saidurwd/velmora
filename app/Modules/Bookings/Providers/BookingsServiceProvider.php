<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Providers;

use App\Modules\Bookings\Console\BookingProbeCommand;
use App\Modules\Platform\Support\ModuleServiceProvider;

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
    }
}
