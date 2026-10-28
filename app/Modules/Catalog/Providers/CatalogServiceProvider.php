<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Contracts\MalwareScanner;
use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Catalog\Services\DatabaseVariantDirectory;
use App\Modules\Catalog\Services\Media\NullMalwareScanner;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;

class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Catalog';
    }

    public function boot(): void
    {
        parent::boot();

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('catalog::admin.menu.categories', 'admin.catalog.categories.index', 'catalog.view', 30);
        $menu->add('catalog::admin.menu.brands', 'admin.catalog.taxonomy.index', 'catalog.view', 31, ['type' => 'brands']);
        $menu->add('catalog::admin.menu.tags', 'admin.catalog.taxonomy.index', 'catalog.view', 32, ['type' => 'tags']);
        $menu->add('catalog::admin.menu.collections', 'admin.catalog.taxonomy.index', 'catalog.view', 33, ['type' => 'collections']);
    }

    public function register(): void
    {
        $this->app->bind(MalwareScanner::class, NullMalwareScanner::class);
        $this->app->bind(VariantDirectory::class, DatabaseVariantDirectory::class);
    }
}
