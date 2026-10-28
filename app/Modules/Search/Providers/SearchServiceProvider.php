<?php

declare(strict_types=1);

namespace App\Modules\Search\Providers;

use App\Modules\Catalog\Events\ProductListingChanged;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Search\Console\ReindexSearchCommand;
use App\Modules\Search\Contracts\CatalogSearch;
use App\Modules\Search\Services\MariaDbCatalogSearch;
use App\Modules\Search\Services\SearchIndexer;
use Illuminate\Support\Facades\Event;

class SearchServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Search';
    }

    public function register(): void
    {
        $this->app->bind(CatalogSearch::class, MariaDbCatalogSearch::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(ProductListingChanged::class, fn (ProductListingChanged $e) => $this->app->make(SearchIndexer::class)->index($e->productId));
        $this->commands([ReindexSearchCommand::class]);

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('search::admin.menu.synonyms', 'admin.search.synonyms.index', 'catalog.view', 36);
        $menu->add('search::admin.menu.zero', 'admin.search.zero.index', 'catalog.view', 37);
    }
}
