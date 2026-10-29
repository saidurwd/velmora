<?php

declare(strict_types=1);

namespace App\Modules\Orders\Providers;

use App\Modules\Orders\Console\OrderProbeCommand;
use App\Modules\Orders\Console\PlaceOrderProbeCommand;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Orders\Contracts\PaymentInitiator;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Listeners\SendOrderConfirmation;
use App\Modules\Orders\Services\CodPaymentInitiator;
use App\Modules\Orders\Services\DatabaseOrderDirectory;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class OrdersServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Orders';
    }

    public function register(): void
    {
        $this->app->bind(PaymentInitiator::class, CodPaymentInitiator::class);
        $this->app->bind(OrderDirectory::class, DatabaseOrderDirectory::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([OrderProbeCommand::class, PlaceOrderProbeCommand::class]);
        $this->app->make(OutboxRegistry::class)->register(OrderPlaced::class);
        Event::listen(OrderPlaced::class, SendOrderConfirmation::class);

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('orders::orders.menu', 'admin.orders.index', 'orders.view', 20);
        $menu->add('orders::fraud.menu_settings', 'admin.orders.fraud.settings', 'settings.view', 21);
        $menu->add('orders::fraud.menu_blocklist', 'admin.orders.fraud.blocklist', 'orders.view', 22);
    }
}
