<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Providers;

use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Privacy\Contracts\PersonalDataRegistry;
use App\Modules\Shipping\Console\ShipmentProbeCommand;
use App\Modules\Shipping\Contracts\DeliveryFigures;
use App\Modules\Shipping\Contracts\ReturnFigures;
use App\Modules\Shipping\Contracts\ShippingQuoter;
use App\Modules\Shipping\Couriers\FakeCourier;
use App\Modules\Shipping\Couriers\ManualCourier;
use App\Modules\Shipping\Events\ShipmentDelivered;
use App\Modules\Shipping\Events\ShipmentFailed;
use App\Modules\Shipping\Events\ShipmentHandedOver;
use App\Modules\Shipping\Events\ShipmentReturned;
use App\Modules\Shipping\Listeners\OpenReturnForReturnedParcel;
use App\Modules\Shipping\Notifications\ReturnTemplates;
use App\Modules\Shipping\Privacy\ReturnPersonalData;
use App\Modules\Shipping\Services\CourierRegistry;
use App\Modules\Shipping\Services\DatabaseDeliveryFigures;
use App\Modules\Shipping\Services\DatabaseReturnFigures;
use App\Modules\Shipping\Services\DatabaseShippingQuoter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

class ShippingServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Shipping';
    }

    public function register(): void
    {
        $this->app->bind(ShippingQuoter::class, DatabaseShippingQuoter::class);
        $this->app->bind(DeliveryFigures::class, DatabaseDeliveryFigures::class);
        $this->app->bind(ReturnFigures::class, DatabaseReturnFigures::class);
        $this->app->singleton(CourierRegistry::class);
    }

    public function boot(): void
    {
        parent::boot();

        $registry = $this->app->make(PersonalDataRegistry::class);
        $registry->addExporter(ReturnPersonalData::class);   // this module's part of a customer's data copy and erasure
        $registry->addEraser(ReturnPersonalData::class, 25);
        $this->commands([ShipmentProbeCommand::class]);
        foreach (ReturnTemplates::all() as $template) {
            $this->app->make(TemplateRegistry::class)->register($template);   // what the shop tells customers about their returns; staff change the words
        }
        Event::listen(ShipmentReturned::class, [OpenReturnForReturnedParcel::class, 'handle']);
        $outbox = $this->app->make(OutboxRegistry::class);
        foreach ([ShipmentHandedOver::class, ShipmentDelivered::class, ShipmentFailed::class, ShipmentReturned::class] as $event) {
            $outbox->register($event);
        }
        // per courier and caller, not shared with anything else
        RateLimiter::for('courier-webhooks', fn (Request $r) => Limit::perMinute(300)->by('courier-webhooks|'.$r->route('courier').'|'.$r->ip()));

        $this->app->make(CourierRegistry::class)->register(ManualCourier::NAME, fn () => new ManualCourier); // always available
        if (! $this->app->environment('production')) {
            $this->app->singleton(FakeCourier::class);
            $this->app->make(CourierRegistry::class)->register(FakeCourier::NAME, fn () => $this->app->make(FakeCourier::class)); // never available in production
        }

        RateLimiter::for('shipping-actions', fn (Request $r) => Limit::perMinute(60)->by('shipping-actions|'.$r->route()?->getName().'|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('shipping-import', fn (Request $r) => Limit::perMinute(10)->by('shipping-import|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('shipping::fulfilment.menu.shipments', 'admin.shipping.shipments.index', 'shipping.view', 56);
        $menu->add('shipping::fulfilment.menu.returns', 'admin.shipping.returns.index', 'shipping.view', 57);
        $menu->add('shipping::fulfilment.menu.issues', 'admin.shipping.issues.index', 'shipping.view', 58);
        $menu->add('shipping::fulfilment.menu.statements', 'admin.shipping.statements.index', 'shipping.view', 59);
        $menu->add('shipping::fulfilment.menu.settings', 'admin.shipping.settings', 'shipping.view', 62);
        $menu->add('shipping::admin.menu.zones', 'admin.shipping.zones.index', 'shipping.view', 60);
        $menu->add('shipping::admin.menu.methods', 'admin.shipping.methods.index', 'shipping.view', 61);
    }
}
