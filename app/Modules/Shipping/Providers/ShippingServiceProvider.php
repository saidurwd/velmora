<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Providers;

use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Shipping\Console\ShipmentProbeCommand;
use App\Modules\Shipping\Contracts\ShippingQuoter;
use App\Modules\Shipping\Couriers\FakeCourier;
use App\Modules\Shipping\Couriers\ManualCourier;
use App\Modules\Shipping\Services\CourierRegistry;
use App\Modules\Shipping\Services\DatabaseShippingQuoter;

class ShippingServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Shipping';
    }

    public function register(): void
    {
        $this->app->bind(ShippingQuoter::class, DatabaseShippingQuoter::class);
        $this->app->singleton(CourierRegistry::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([ShipmentProbeCommand::class]);

        $this->app->make(CourierRegistry::class)->register(ManualCourier::NAME, fn () => new ManualCourier); // always available
        if (! $this->app->environment('production')) {
            $this->app->singleton(FakeCourier::class);
            $this->app->make(CourierRegistry::class)->register(FakeCourier::NAME, fn () => $this->app->make(FakeCourier::class)); // never available in production
        }

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('shipping::admin.menu.zones', 'admin.shipping.zones.index', 'shipping.view', 60);
        $menu->add('shipping::admin.menu.methods', 'admin.shipping.methods.index', 'shipping.view', 61);
    }
}
