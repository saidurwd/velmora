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
use App\Modules\Payments\Gateways\SslCommerzGateway;
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

        $this->registerSslCommerz($registry, (array) config('payments.sslcommerz'), $this->app->environment('production'), (bool) config('payments.allow_live'));

        $outbox = $this->app->make(OutboxRegistry::class);
        foreach ([PaymentInitiated::class, PaymentSucceeded::class, PaymentFailed::class, PaymentExpired::class, PaymentNeedsReview::class] as $event) {
            $outbox->register($event);
        }
    }

    /**
     * SSLCommerz is available only when its credentials are set. LIVE mode (the real bank) needs all three: production, sandbox switched off,
     * and an explicit PAYMENTS_ALLOW_LIVE. A development or test machine that happens to hold live credentials therefore cannot reach it.
     */
    /** @param  array<string, mixed>  $config  store_id, store_password, sandbox */
    public function registerSslCommerz(GatewayRegistry $registry, array $config, bool $production, bool $allowLive): void
    {
        $id = trim((string) ($config['store_id'] ?? ''));
        $password = (string) ($config['store_password'] ?? '');
        $sandbox = (bool) ($config['sandbox'] ?? true);

        if ($id === '' || $password === '') {
            return;
        }
        if (! $sandbox && ! ($production && $allowLive)) {
            return;
        }

        $registry->register(SslCommerzGateway::NAME, fn () => new SslCommerzGateway($id, $password, $sandbox, (string) (config('mail.from.address') ?: 'noreply@example.com')));
    }
}
