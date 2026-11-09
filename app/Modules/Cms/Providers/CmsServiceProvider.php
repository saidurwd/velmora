<?php

declare(strict_types=1);

namespace App\Modules\Cms\Providers;

use App\Modules\Cms\Console\GenerateSitemapsCommand;
use App\Modules\Cms\Contracts\BannerSource;
use App\Modules\Cms\Contracts\HomeLayout;
use App\Modules\Cms\Contracts\MenuReader;
use App\Modules\Cms\Contracts\PageDirectory;
use App\Modules\Cms\Contracts\PolicyAcceptances;
use App\Modules\Cms\Privacy\PolicyPersonalData;
use App\Modules\Cms\Services\DatabaseBannerSource;
use App\Modules\Cms\Services\DatabaseHomeLayout;
use App\Modules\Cms\Services\DatabaseMenuReader;
use App\Modules\Cms\Services\DatabasePageDirectory;
use App\Modules\Cms\Services\DatabasePolicyAcceptances;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Privacy\Contracts\PersonalDataRegistry;

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
        $this->app->bind(PolicyAcceptances::class, DatabasePolicyAcceptances::class);
        $this->app->bind(MenuReader::class, DatabaseMenuReader::class);
        $this->app->bind(HomeLayout::class, DatabaseHomeLayout::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([GenerateSitemapsCommand::class]);

        // acceptances are part of a customer's data copy and are anonymised when the customer is erased
        $registry = $this->app->make(PersonalDataRegistry::class);
        $registry->addExporter(PolicyPersonalData::class);
        $registry->addEraser(PolicyPersonalData::class, 55);

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('cms::admin.menu.pages', 'admin.cms.pages.index', 'cms.view', 60);
        $menu->add('cms::admin.menu.banners', 'admin.cms.banners.index', 'cms.view', 61);
        $menu->add('cms::faq.menu', 'admin.cms.faq.index', 'cms.view', 62);
        $menu->add('cms::policies.menu', 'admin.cms.policies.index', 'cms.view', 63);
        $menu->add('cms::menu.menu', 'admin.cms.menus.index', 'cms.view', 64);
        $menu->add('cms::gaps.menu', 'admin.cms.translations', 'cms.view', 65);
        $menu->add('cms::home.menu', 'admin.cms.home.index', 'cms.view', 66);
    }
}
