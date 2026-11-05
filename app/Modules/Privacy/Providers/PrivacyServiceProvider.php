<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Providers;

use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Privacy\Contracts\PersonalDataRegistry;
use App\Modules\Privacy\Notifications\PrivacyTemplates;
use App\Modules\Privacy\Services\PersonalDataCatalog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class PrivacyServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Privacy';
    }

    public function register(): void
    {
        $this->app->singleton(PersonalDataCatalog::class);
        $this->app->bind(PersonalDataRegistry::class, fn ($app) => $app->make(PersonalDataCatalog::class));
    }

    public function boot(): void
    {
        parent::boot();

        $by = fn (Request $r): string => (string) ($r->user('web')?->getAuthIdentifier() ?? $r->ip());
        RateLimiter::for('privacy', fn (Request $r) => Limit::perMinute(10)->by('privacy|'.$by($r)));
        RateLimiter::for('privacy-staff', fn (Request $r) => Limit::perMinute(30)->by('privacy-staff|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));

        foreach (PrivacyTemplates::all() as $template) {
            $this->app->make(TemplateRegistry::class)->register($template);   // what the shop says about these requests; staff change the words
        }
        $this->app->make(AdminMenu::class)->add('privacy::admin.menu', 'admin.privacy.index', 'privacy.view', 72);
    }
}
