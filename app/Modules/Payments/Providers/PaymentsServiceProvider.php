<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Console\CodProbeCommand;
use App\Modules\Payments\Console\PaymentProbeCommand;
use App\Modules\Payments\Console\ReconcileProbeCommand;
use App\Modules\Payments\Console\RefundProbeCommand;
use App\Modules\Payments\Contracts\CodAgingFigures;
use App\Modules\Payments\Contracts\CodCashLedger;
use App\Modules\Payments\Contracts\PaymentFigures;
use App\Modules\Payments\Contracts\PaymentMethodCatalog;
use App\Modules\Payments\Contracts\RefundReasonFigures;
use App\Modules\Payments\Dashboard\FailedPaymentsTile;
use App\Modules\Payments\Dashboard\PaymentsNeedReviewTile;
use App\Modules\Payments\Dashboard\RefundQueueTile;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Events\PaymentExpired;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentInitiated;
use App\Modules\Payments\Events\PaymentNeedsReview;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Events\RefundSucceeded;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Gateways\SslCommerzGateway;
use App\Modules\Payments\Integrity\RefundsWithinCapturedCheck;
use App\Modules\Payments\Listeners\CollectCashOnDelivery;
use App\Modules\Payments\Services\DatabaseCodAgingFigures;
use App\Modules\Payments\Services\DatabaseCodCashLedger;
use App\Modules\Payments\Services\DatabasePaymentFigures;
use App\Modules\Payments\Services\DatabasePaymentMethodCatalog;
use App\Modules\Payments\Services\DatabaseRefundReasonFigures;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Payments\Services\OnlinePaymentInitiator;
use App\Modules\Payments\Support\SslCommerzConnectionTest;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\DashboardRegistry;
use App\Modules\Platform\Support\IntegrationCredentials;
use App\Modules\Platform\Support\IntegrationTests;
use App\Modules\Platform\Support\IntegrityRegistry;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Platform\Support\SavedFilterScreens;
use App\Modules\Shipping\Events\ShipmentDelivered;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

class PaymentsServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Payments';
    }

    public function register(): void
    {
        $this->commands([PaymentProbeCommand::class, RefundProbeCommand::class, CodProbeCommand::class, ReconcileProbeCommand::class]);
        $this->app->singleton(GatewayRegistry::class);
        $this->app->singleton(FakeGateway::class);
        $this->app->bind(PaymentMethodCatalog::class, DatabasePaymentMethodCatalog::class);
        $this->app->bind(CodCashLedger::class, DatabaseCodCashLedger::class);
        $this->app->bind(PaymentFigures::class, DatabasePaymentFigures::class);
        $this->app->bind(RefundReasonFigures::class, DatabaseRefundReasonFigures::class);
        $this->app->bind(CodAgingFigures::class, DatabaseCodAgingFigures::class);
        $this->app->tag([OnlinePaymentInitiator::class], 'orders.payment_initiators'); // Orders starts payment through this
    }

    public function boot(): void
    {
        parent::boot();

        $dashboard = $this->app->make(DashboardRegistry::class);
        foreach ([PaymentsNeedReviewTile::class, FailedPaymentsTile::class, RefundQueueTile::class] as $tile) {
            $dashboard->add($tile);
        }
        $this->app->make(SavedFilterScreens::class)->add('payments', 'admin.payments.index', 'payments.view', [
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:'.implode(',', array_column(PaymentState::cases(), 'value'))],
            'provider' => ['nullable', 'string', 'max:30'],
        ]);

        // data-integrity checks this module owns (run from the staff Operations page and nightly)
        $integrity = $this->app->make(IntegrityRegistry::class);
        $integrity->add(RefundsWithinCapturedCheck::class);
        $this->app->make(IntegrationTests::class)->add('sslcommerz', SslCommerzConnectionTest::class);

        $registry = $this->app->make(GatewayRegistry::class);
        if (! $this->app->environment('production')) {
            $registry->register('fake', fn () => $this->app->make(FakeGateway::class)); // never available in production
        }

        // SSLCommerz: its credentials may be saved by staff (Settings → Integrations) or set in the server's environment, so availability is decided on first use
        $registry->registerResolver(SslCommerzGateway::NAME, function (): ?Closure {
            $probe = new GatewayRegistry;
            $this->registerSslCommerz($probe, $this->app->make(IntegrationCredentials::class)->sslcommerz(), $this->app->environment('production'), (bool) config('payments.allow_live'));

            return $probe->has(SslCommerzGateway::NAME) ? fn () => $probe->for(SslCommerzGateway::NAME) : null;
        });

        $outbox = $this->app->make(OutboxRegistry::class);
        foreach ([PaymentInitiated::class, PaymentSucceeded::class, PaymentFailed::class, PaymentExpired::class, PaymentNeedsReview::class, RefundSucceeded::class] as $event) {
            $outbox->register($event);
        }

        // Per person AND per route: Laravel's throttle:N,M shares one counter per person across every route, so unrelated screens would eat each other's allowance.
        RateLimiter::for('payments-actions', fn (Request $r) => Limit::perMinute(60)->by('payments-actions|'.$r->route()?->getName().'|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('payments-import', fn (Request $r) => Limit::perMinute(10)->by('payments-import|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));

        Event::listen(ShipmentDelivered::class, [CollectCashOnDelivery::class, 'handle']);

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('payments::admin.menu_today', 'admin.payments.today', 'payments.view', 30);
        $menu->add('payments::admin.menu_payments', 'admin.payments.index', 'payments.view', 31);
        $menu->add('payments::admin.menu_refunds', 'admin.payments.refunds.index', 'payments.view', 32);
        $menu->add('payments::admin.menu_cod', 'admin.payments.cod.index', 'payments.view', 33);
        $menu->add('payments::admin.menu_reconciliation', 'admin.payments.reconciliation.index', 'payments.view', 34);
        $menu->add('payments::admin.menu_settings', 'admin.payments.settings', 'payments.view', 35);
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
