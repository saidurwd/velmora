<?php

declare(strict_types=1);

namespace App\Modules\Orders\Providers;

use App\Modules\Orders\Console\OrderProbeCommand;
use App\Modules\Orders\Console\PlaceOrderProbeCommand;
use App\Modules\Orders\Contracts\CodOrders;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Orders\Contracts\OrderPayables;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Listeners\ApplyPaymentEvent;
use App\Modules\Orders\Listeners\SendOrderConfirmation;
use App\Modules\Orders\Services\CodPaymentInitiator;
use App\Modules\Orders\Services\DatabaseCodOrders;
use App\Modules\Orders\Services\DatabaseOrderDirectory;
use App\Modules\Orders\Services\DatabaseOrderPayables;
use App\Modules\Orders\Services\PaymentInitiators;
use App\Modules\Payments\Events\PaymentExpired;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentInitiated;
use App\Modules\Payments\Events\PaymentNeedsReview;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Events\RefundSucceeded;
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
        $this->app->tag([CodPaymentInitiator::class], 'orders.payment_initiators'); // Payments adds the online one
        $this->app->singleton(PaymentInitiators::class, fn ($app) => new PaymentInitiators($app->tagged('orders.payment_initiators')));
        $this->app->bind(OrderPayables::class, DatabaseOrderPayables::class);
        $this->app->bind(CodOrders::class, DatabaseCodOrders::class);
        $this->app->bind(OrderDirectory::class, DatabaseOrderDirectory::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([OrderProbeCommand::class, PlaceOrderProbeCommand::class]);
        $this->app->make(OutboxRegistry::class)->register(OrderPlaced::class);
        Event::listen(OrderPlaced::class, SendOrderConfirmation::class);
        Event::listen(PaymentInitiated::class, [ApplyPaymentEvent::class, 'initiated']);
        Event::listen(PaymentSucceeded::class, [ApplyPaymentEvent::class, 'succeeded']);
        Event::listen(PaymentFailed::class, [ApplyPaymentEvent::class, 'failed']);
        Event::listen(PaymentExpired::class, [ApplyPaymentEvent::class, 'expired']);
        Event::listen(PaymentNeedsReview::class, [ApplyPaymentEvent::class, 'needsReview']);
        Event::listen(RefundSucceeded::class, [ApplyPaymentEvent::class, 'refundSucceeded']);

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('orders::orders.menu', 'admin.orders.index', 'orders.view', 20);
        $menu->add('orders::fraud.menu_settings', 'admin.orders.fraud.settings', 'settings.view', 21);
        $menu->add('orders::fraud.menu_blocklist', 'admin.orders.fraud.blocklist', 'orders.view', 22);
    }
}
