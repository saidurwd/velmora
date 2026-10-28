<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Providers;

use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Pricing\Contracts\PriceCatalog;
use App\Modules\Pricing\Contracts\PriceWriter;
use App\Modules\Pricing\Contracts\TaxRates;
use App\Modules\Pricing\Services\ActionPriceWriter;
use App\Modules\Pricing\Services\DatabaseTaxRates;
use App\Modules\Pricing\Services\PriceResolver;

class PricingServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Pricing';
    }

    public function boot(): void
    {
        parent::boot();

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('pricing::admin.menu.prices', 'admin.pricing.index', 'pricing.view', 40);
        $menu->add('pricing::admin.menu.bulk', 'admin.pricing.bulk', 'pricing.update', 41);
    }

    public function register(): void
    {
        $this->app->bind(TaxRates::class, DatabaseTaxRates::class);
        $this->app->bind(PriceCatalog::class, PriceResolver::class);
        $this->app->bind(PriceWriter::class, ActionPriceWriter::class);
    }
}
