<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Contracts\PaymentMethodCatalog;
use App\Modules\Payments\Events\PaymentExpired;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentInitiated;
use App\Modules\Payments\Events\PaymentNeedsReview;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Services\DatabasePaymentMethodCatalog;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Payments\Services\OnlinePaymentInitiator;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\ModuleServiceProvider;

class PaymentsServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Payments';
    }

    public function register(): void
    {
        $this->app->singleton(GatewayRegistry::class);
        $this->app->singleton(FakeGateway::class);
        $this->app->bind(PaymentMethodCatalog::class, DatabasePaymentMethodCatalog::class);
        $this->app->tag([OnlinePaymentInitiator::class], 'orders.payment_initiators'); // Orders starts payment through this
    }

    public function boot(): void
    {
        parent::boot();

        $registry = $this->app->make(GatewayRegistry::class);
        if (! $this->app->environment('production')) {
            $registry->register('fake', fn () => $this->app->make(FakeGateway::class)); // never available in production
        }

        $outbox = $this->app->make(OutboxRegistry::class);
        foreach ([PaymentInitiated::class, PaymentSucceeded::class, PaymentFailed::class, PaymentExpired::class, PaymentNeedsReview::class] as $event) {
            $outbox->register($event);
        }
    }
}
