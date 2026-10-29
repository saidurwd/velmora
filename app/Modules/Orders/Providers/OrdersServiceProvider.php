<?php

declare(strict_types=1);

namespace App\Modules\Orders\Providers;

use App\Modules\Orders\Console\OrderProbeCommand;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;

class OrdersServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Orders';
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([OrderProbeCommand::class]);

        $this->app->make(AdminMenu::class)->add('orders::orders.menu', 'admin.orders.index', 'orders.view', 20);
    }
}
