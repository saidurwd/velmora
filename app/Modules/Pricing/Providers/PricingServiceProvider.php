<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Providers;

use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Pricing\Contracts\PriceCatalog;
use App\Modules\Pricing\Contracts\TaxRates;
use App\Modules\Pricing\Services\DatabaseTaxRates;
use App\Modules\Pricing\Services\PriceResolver;

class PricingServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Pricing';
    }

    public function register(): void
    {
        $this->app->bind(TaxRates::class, DatabaseTaxRates::class);
        $this->app->bind(PriceCatalog::class, PriceResolver::class);
    }
}
