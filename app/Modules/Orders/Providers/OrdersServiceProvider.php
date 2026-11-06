<?php

declare(strict_types=1);

namespace App\Modules\Orders\Providers;

use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Orders\Console\AccountProbeCommand;
use App\Modules\Orders\Console\OrderProbeCommand;
use App\Modules\Orders\Console\PlaceOrderProbeCommand;
use App\Modules\Orders\Contracts\CodOrders;
use App\Modules\Orders\Contracts\CustomerOrders;
use App\Modules\Orders\Contracts\DigitalOrderLines;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Orders\Contracts\OrderPayables;
use App\Modules\Orders\Contracts\PurchasedLines;
use App\Modules\Orders\Contracts\ServiceOrderLines;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Events\OrderRefunded;
use App\Modules\Orders\Listeners\ApplyPaymentEvent;
use App\Modules\Orders\Listeners\ApplyShipmentEvent;
use App\Modules\Orders\Listeners\SendOrderConfirmation;
use App\Modules\Orders\Notifications\OrderTemplates;
use App\Modules\Orders\Privacy\OrderPersonalData;
use App\Modules\Orders\Services\CodPaymentInitiator;
use App\Modules\Orders\Services\DatabaseCodOrders;
use App\Modules\Orders\Services\DatabaseCustomerOrders;
use App\Modules\Orders\Services\DatabaseDigitalOrderLines;
use App\Modules\Orders\Services\DatabaseOrderDirectory;
use App\Modules\Orders\Services\DatabaseOrderFulfilment;
use App\Modules\Orders\Services\DatabaseOrderPayables;
use App\Modules\Orders\Services\DatabasePurchasedLines;
use App\Modules\Orders\Services\DatabaseServiceOrderLines;
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
use App\Modules\Privacy\Contracts\PersonalDataRegistry;
use App\Modules\Shipping\Events\ShipmentDelivered;
use App\Modules\Shipping\Events\ShipmentFailed;
use App\Modules\Shipping\Events\ShipmentHandedOver;
use App\Modules\Shipping\Events\ShipmentReturned;
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
        $this->app->bind(OrderFulfilment::class, DatabaseOrderFulfilment::class);
        $this->app->bind(OrderDirectory::class, DatabaseOrderDirectory::class);
        $this->app->bind(DigitalOrderLines::class, DatabaseDigitalOrderLines::class);
        $this->app->bind(ServiceOrderLines::class, DatabaseServiceOrderLines::class);
        $this->app->bind(CustomerOrders::class, DatabaseCustomerOrders::class);
        $this->app->bind(PurchasedLines::class, DatabasePurchasedLines::class);
    }

    public function boot(): void
    {
        parent::boot();
        // This module's part of a customer's data export and erasure (the Privacy module asks every module for its own).
        $registry = $this->app->make(PersonalDataRegistry::class);
        $registry->addExporter(OrderPersonalData::class);
        $registry->addEraser(OrderPersonalData::class, 20);

        $this->commands([OrderProbeCommand::class, PlaceOrderProbeCommand::class, AccountProbeCommand::class]);
        $this->app->make(OutboxRegistry::class)->register(OrderPlaced::class);
        $this->app->make(OutboxRegistry::class)->register(OrderPaid::class);
        $this->app->make(OutboxRegistry::class)->register(OrderRefunded::class);
        $this->app->make(OutboxRegistry::class)->register(OrderCancelled::class);
        foreach (OrderTemplates::all() as $template) {
            $this->app->make(TemplateRegistry::class)->register($template);   // the messages this module sends; staff change their words
        }
        Event::listen(OrderPlaced::class, SendOrderConfirmation::class);
        Event::listen(PaymentInitiated::class, [ApplyPaymentEvent::class, 'initiated']);
        Event::listen(PaymentSucceeded::class, [ApplyPaymentEvent::class, 'succeeded']);
        Event::listen(PaymentFailed::class, [ApplyPaymentEvent::class, 'failed']);
        Event::listen(PaymentExpired::class, [ApplyPaymentEvent::class, 'expired']);
        Event::listen(PaymentNeedsReview::class, [ApplyPaymentEvent::class, 'needsReview']);
        Event::listen(RefundSucceeded::class, [ApplyPaymentEvent::class, 'refundSucceeded']);
        Event::listen(ShipmentHandedOver::class, [ApplyShipmentEvent::class, 'handedOver']);
        Event::listen(ShipmentDelivered::class, [ApplyShipmentEvent::class, 'delivered']);
        Event::listen(ShipmentFailed::class, [ApplyShipmentEvent::class, 'failed']);
        Event::listen(ShipmentReturned::class, [ApplyShipmentEvent::class, 'returned']);

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('orders::orders.menu', 'admin.orders.index', 'orders.view', 20);
        $menu->add('orders::fraud.menu_settings', 'admin.orders.fraud.settings', 'settings.view', 21);
        $menu->add('orders::fraud.menu_blocklist', 'admin.orders.fraud.blocklist', 'orders.view', 22);
        $menu->add('orders::cancel-requests.menu', 'admin.orders.cancellations.index', 'orders.cancel', 23);
    }
}
