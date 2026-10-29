<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Providers;

use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Pricing\Actions\RedeemCoupons;
use App\Modules\Pricing\Console\CouponProbeCommand;
use App\Modules\Pricing\Contracts\CartPricer;
use App\Modules\Pricing\Contracts\CouponRedeemer;
use App\Modules\Pricing\Contracts\DiscountEvaluator;
use App\Modules\Pricing\Contracts\PriceCatalog;
use App\Modules\Pricing\Contracts\PriceWriter;
use App\Modules\Pricing\Contracts\TaxRates;
use App\Modules\Pricing\Services\ActionPriceWriter;
use App\Modules\Pricing\Services\DatabaseDiscountEvaluator;
use App\Modules\Pricing\Services\DatabaseTaxRates;
use App\Modules\Pricing\Services\EngineCartPricer;
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
        $menu->add('pricing::promotions.menu_coupons', 'admin.promotions.coupons.index', 'promotions.view', 42);
        $menu->add('pricing::promotions.menu_promotions', 'admin.promotions.promotions.index', 'promotions.view', 43);
    }

    public function register(): void
    {
        $this->app->bind(TaxRates::class, DatabaseTaxRates::class);
        $this->app->bind(PriceCatalog::class, PriceResolver::class);
        $this->app->bind(PriceWriter::class, ActionPriceWriter::class);
        $this->app->bind(CartPricer::class, EngineCartPricer::class);
        $this->app->bind(DiscountEvaluator::class, DatabaseDiscountEvaluator::class);
        $this->app->bind(CouponRedeemer::class, RedeemCoupons::class);
        $this->commands([CouponProbeCommand::class]);
    }
}
