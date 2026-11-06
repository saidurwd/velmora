<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Providers;

use App\Modules\Engagement\Notifications\EngagementTemplates;
use App\Modules\Engagement\Privacy\EngagementPersonalData;
use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Privacy\Contracts\PersonalDataRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class EngagementServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Engagement';
    }

    public function boot(): void
    {
        parent::boot();

        // A customer may send a handful of reviews an hour; staff decisions are limited per person.
        RateLimiter::for('reviews', fn (Request $r) => Limit::perHour(5)->by('reviews|'.($r->user('web')?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('reviews-staff', fn (Request $r) => Limit::perMinute(60)->by('reviews-staff|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));
        $this->app->make(AdminMenu::class)->add('engagement::reviews.menu', 'admin.reviews.index', 'reviews.moderate', 73);

        // This module's part of a customer's data export and erasure (the Privacy module asks every module for its own).
        $registry = $this->app->make(PersonalDataRegistry::class);
        $registry->addExporter(EngagementPersonalData::class);
        $registry->addEraser(EngagementPersonalData::class, 50);

        foreach (EngagementTemplates::all() as $template) {
            $this->app->make(TemplateRegistry::class)->register($template);   // the messages this module sends; staff change their words
        }
    }
}
