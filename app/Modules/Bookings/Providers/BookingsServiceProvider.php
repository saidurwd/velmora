<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Providers;

use App\Modules\Bookings\Actions\ClaimBookingHold;
use App\Modules\Bookings\Console\ApiHoldProbeCommand;
use App\Modules\Bookings\Console\BookingProbeCommand;
use App\Modules\Bookings\Contracts\BookingFigures;
use App\Modules\Bookings\Contracts\BookingHolds;
use App\Modules\Bookings\Contracts\CancellationPolicyTexts;
use App\Modules\Bookings\Events\BookingEnded;
use App\Modules\Bookings\Events\BookingRescheduled;
use App\Modules\Bookings\Listeners\ConfirmBookingsForPaidOrder;
use App\Modules\Bookings\Listeners\EndBookingsOfRefundedOrder;
use App\Modules\Bookings\Listeners\ReleaseBookingsForCancelledOrder;
use App\Modules\Bookings\Notifications\BookingTemplates;
use App\Modules\Bookings\Privacy\BookingPersonalData;
use App\Modules\Bookings\Services\DatabaseBookingFigures;
use App\Modules\Bookings\Services\DatabaseCancellationPolicyTexts;
use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderRefunded;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Privacy\Contracts\PersonalDataRegistry;
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
        $this->app->bind(BookingFigures::class, DatabaseBookingFigures::class);
        $this->app->bind(CancellationPolicyTexts::class, DatabaseCancellationPolicyTexts::class);
    }

    public function boot(): void
    {
        parent::boot();
        // This module's part of a customer's data export and erasure (the Privacy module asks every module for its own).
        $registry = $this->app->make(PersonalDataRegistry::class);
        $registry->addExporter(BookingPersonalData::class);
        $registry->addEraser(BookingPersonalData::class, 30);

        $this->commands([BookingProbeCommand::class, ApiHoldProbeCommand::class]);
        foreach (BookingTemplates::all() as $template) {
            $this->app->make(TemplateRegistry::class)->register($template);   // the messages this module sends; staff change their words
        }
        Event::listen(OrderPaid::class, ConfirmBookingsForPaidOrder::class);
        Event::listen(OrderCancelled::class, ReleaseBookingsForCancelledOrder::class);
        Event::listen(OrderRefunded::class, EndBookingsOfRefundedOrder::class);
        $this->app->make(OutboxRegistry::class)->register(BookingEnded::class);
        $this->app->make(OutboxRegistry::class)->register(BookingRescheduled::class);
        RateLimiter::for('booking-manage', fn (Request $r) => Limit::perMinute(20)->by('booking-manage|'.($r->user('web')?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('bookings-actions', fn (Request $r) => Limit::perMinute(30)->by('bookings-actions|'.$r->route()?->getName().'|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));
        $menu = $this->app->make(AdminMenu::class);
        $menu->add('bookings::admin.menu.bookings', 'admin.bookings.index', 'bookings.view', 60);
        $menu->add('bookings::admin.menu.calendar', 'admin.bookings.calendar', 'bookings.view', 61);
        $menu->add('bookings::admin.menu.setup', 'admin.bookings.setup.services', 'bookings.view', 62);
        // per visitor, not shared with anything else
        RateLimiter::for('booking-hold', fn (Request $r) => Limit::perMinute(20)->by('booking-hold|'.($r->user('web')?->getAuthIdentifier() ?? $r->ip())));
    }
}
