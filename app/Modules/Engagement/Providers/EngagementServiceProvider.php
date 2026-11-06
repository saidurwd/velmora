<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Providers;

use App\Modules\Engagement\Notifications\CartReminderTemplates;
use App\Modules\Engagement\Notifications\EngagementTemplates;
use App\Modules\Engagement\Privacy\EngagementPersonalData;
use App\Modules\Engagement\Privacy\SupportPersonalData;
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
        RateLimiter::for('support-new', fn (Request $r) => Limit::perDay(5)->by('support-new|'.($r->user('web')?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('support-reply', fn (Request $r) => Limit::perHour(20)->by('support-reply|'.($r->user('web')?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('support-read', fn (Request $r) => Limit::perMinute(60)->by('support-read|'.($r->user('web')?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('support-staff', fn (Request $r) => Limit::perMinute(120)->by('support-staff|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));
        $menu = $this->app->make(AdminMenu::class);
        $menu->add('engagement::reviews.menu', 'admin.reviews.index', 'reviews.moderate', 73);
        $menu->add('engagement::support.menu', 'admin.support.index', 'support.view', 74);
        $menu->add('engagement::support.menu_canned', 'admin.support.canned.index', 'support.manage', 75);
        $menu->add('engagement::support.menu_contact', 'admin.support.contact', 'support.manage', 76);

        // This module's part of a customer's data export and erasure (the Privacy module asks every module for its own).
        $registry = $this->app->make(PersonalDataRegistry::class);
        $registry->addExporter(EngagementPersonalData::class);
        $registry->addEraser(EngagementPersonalData::class, 50);
        $registry->addExporter(SupportPersonalData::class);
        $registry->addEraser(SupportPersonalData::class, 45);

        foreach ([...EngagementTemplates::all(), ...CartReminderTemplates::all()] as $template) {
            $this->app->make(TemplateRegistry::class)->register($template);   // the messages this module sends; staff change their words
        }
    }
}
