<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Providers;

use App\Modules\Engagement\Actions\OpenSupportTicket;
use App\Modules\Engagement\Bulk\ApproveReviews;
use App\Modules\Engagement\Bulk\AssignTickets;
use App\Modules\Engagement\Bulk\RejectReviews;
use App\Modules\Engagement\Dashboard\LateTicketsTile;
use App\Modules\Engagement\Dashboard\OpenTicketsTile;
use App\Modules\Engagement\Dashboard\ReviewsWaitingTile;
use App\Modules\Engagement\Notifications\CartReminderTemplates;
use App\Modules\Engagement\Notifications\EngagementTemplates;
use App\Modules\Engagement\Privacy\EngagementPersonalData;
use App\Modules\Engagement\Privacy\SupportPersonalData;
use App\Modules\Engagement\Search\TicketSearch;
use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\BulkActions;
use App\Modules\Platform\Support\DashboardRegistry;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Platform\Support\SavedFilterScreens;
use App\Modules\Platform\Support\SearchProviders;
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

        $dashboard = $this->app->make(DashboardRegistry::class);
        foreach ([ReviewsWaitingTile::class, OpenTicketsTile::class, LateTicketsTile::class] as $tile) {
            $dashboard->add($tile);
        }
        $this->app->make(SearchProviders::class)->add(TicketSearch::class);
        foreach ([ApproveReviews::class, RejectReviews::class, AssignTickets::class] as $bulk) {
            $this->app->make(BulkActions::class)->add($bulk);
        }
        $this->app->make(SavedFilterScreens::class)->add('support', 'admin.support.index', 'support.view', [
            'status' => ['nullable', 'in:active,open,answered,closed'], 'assignee' => ['nullable', 'in:mine,none'], 'category' => ['nullable', 'in:'.implode(',', OpenSupportTicket::CATEGORIES)],
            'overdue' => ['nullable', 'in:1'], 'q' => ['nullable', 'string', 'max:12'],
        ]);

        // A customer may send a handful of reviews an hour; staff decisions are limited per person.
        RateLimiter::for('reviews', fn (Request $r) => Limit::perHour(5)->by('reviews|'.($r->user('web')?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('reviews-staff', fn (Request $r) => Limit::perMinute(60)->by('reviews-staff|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('support-new', fn (Request $r) => Limit::perDay(5)->by('support-new|'.(($r->user('web') ?? $r->user())?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('support-reply', fn (Request $r) => Limit::perHour(20)->by('support-reply|'.(($r->user('web') ?? $r->user())?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('support-read', fn (Request $r) => Limit::perMinute(60)->by('support-read|'.(($r->user('web') ?? $r->user())?->getAuthIdentifier() ?? $r->ip())));
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
