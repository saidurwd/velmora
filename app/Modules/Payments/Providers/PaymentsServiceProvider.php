<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Services\GatewayRegistry;
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
    }

    public function boot(): void
    {
        parent::boot();

        $registry = $this->app->make(GatewayRegistry::class);
        if (! $this->app->environment('production')) {
            $registry->register('fake', fn () => $this->app->make(FakeGateway::class)); // never available in production
        }
    }
}
