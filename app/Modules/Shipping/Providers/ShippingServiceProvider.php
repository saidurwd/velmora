<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Providers;

use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Shipping\Contracts\ShippingQuoter;
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
    }

    public function boot(): void
    {
        parent::boot();

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('shipping::admin.menu.zones', 'admin.shipping.zones.index', 'shipping.view', 60);
        $menu->add('shipping::admin.menu.methods', 'admin.shipping.methods.index', 'shipping.view', 61);
    }
}
