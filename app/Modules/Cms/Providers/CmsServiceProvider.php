<?php

declare(strict_types=1);

namespace App\Modules\Cms\Providers;

use App\Modules\Cms\Contracts\BannerSource;
use App\Modules\Cms\Contracts\PageDirectory;
use App\Modules\Cms\Services\DatabaseBannerSource;
use App\Modules\Cms\Services\DatabasePageDirectory;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;

class CmsServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Cms';
    }

    public function register(): void
    {
        $this->app->bind(BannerSource::class, DatabaseBannerSource::class);
        $this->app->bind(PageDirectory::class, DatabasePageDirectory::class);
    }

    public function boot(): void
    {
        parent::boot();

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('cms::admin.menu.pages', 'admin.cms.pages.index', 'cms.view', 60);
        $menu->add('cms::admin.menu.banners', 'admin.cms.banners.index', 'cms.view', 61);
    }
}
