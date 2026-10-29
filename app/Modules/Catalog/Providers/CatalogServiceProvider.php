<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Console\ReindexListingCommand;
use App\Modules\Catalog\Contracts\MalwareScanner;
use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Catalog\Contracts\SearchableProducts;
use App\Modules\Catalog\Contracts\SitemapSource;
use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Catalog\Events\ProductListingChanged;
use App\Modules\Catalog\Services\DatabasePurchasableVariants;
use App\Modules\Catalog\Services\DatabaseVariantDirectory;
use App\Modules\Catalog\Services\Media\NullMalwareScanner;
use App\Modules\Catalog\Services\Storefront\DatabaseSearchableProducts;
use App\Modules\Catalog\Services\Storefront\DatabaseSitemapSource;
use App\Modules\Catalog\Services\Storefront\ListingProjection;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Platform\Support\StorefrontCache;
use Illuminate\Support\Facades\Event;

class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Catalog';
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(ProductListingChanged::class, function (ProductListingChanged $e): void {
            $this->app->make(ListingProjection::class)->refresh($e->productId);
            $this->app->make(StorefrontCache::class)->bump();
        });
        $this->commands([ReindexListingCommand::class]);

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('catalog::admin.menu.products', 'admin.catalog.products.index', 'catalog.view', 29);
        $menu->add('catalog::admin.menu.categories', 'admin.catalog.categories.index', 'catalog.view', 30);
        $menu->add('catalog::admin.menu.import', 'admin.catalog.import.index', 'catalog.view', 35);
        $menu->add('catalog::admin.menu.attributes', 'admin.catalog.attributes.index', 'catalog.view', 34);
        $menu->add('catalog::admin.menu.brands', 'admin.catalog.taxonomy.index', 'catalog.view', 31, ['type' => 'brands']);
        $menu->add('catalog::admin.menu.tags', 'admin.catalog.taxonomy.index', 'catalog.view', 32, ['type' => 'tags']);
        $menu->add('catalog::admin.menu.collections', 'admin.catalog.taxonomy.index', 'catalog.view', 33, ['type' => 'collections']);
    }

    public function register(): void
    {
        $this->app->bind(SearchableProducts::class, DatabaseSearchableProducts::class);
        $this->app->bind(SitemapSource::class, DatabaseSitemapSource::class);
        $this->app->bind(PurchasableVariants::class, DatabasePurchasableVariants::class);
        $this->app->bind(MalwareScanner::class, NullMalwareScanner::class);
        $this->app->bind(VariantDirectory::class, DatabaseVariantDirectory::class);
    }
}
