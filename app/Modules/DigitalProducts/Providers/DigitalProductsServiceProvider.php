<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Providers;

use App\Modules\DigitalProducts\Console\DigitalProbeCommand;
use App\Modules\DigitalProducts\Contracts\RefundPolicyTexts;
use App\Modules\DigitalProducts\Events\DigitalAssetVersionPublished;
use App\Modules\DigitalProducts\Listeners\GrantEntitlementsForPaidOrder;
use App\Modules\DigitalProducts\Listeners\NotifyOwnersOfNewVersion;
use App\Modules\DigitalProducts\Listeners\RevokeForRefundedOrder;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\DigitalProducts\Notifications\DigitalTemplates;
use App\Modules\DigitalProducts\Policies\EntitlementPolicy;
use App\Modules\DigitalProducts\Services\DatabaseRefundPolicyTexts;
use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderRefunded;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

class DigitalProductsServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'DigitalProducts';
    }

    public function register(): void
    {
        $this->app->bind(RefundPolicyTexts::class, DatabaseRefundPolicyTexts::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([DigitalProbeCommand::class]);
        Gate::policy(Entitlement::class, EntitlementPolicy::class);
        RateLimiter::for('digital-download', fn (Request $r) => Limit::perMinute(20)->by('digital-download|'.($r->user('web')?->getAuthIdentifier() ?? $r->ip()).'|'.($r->route('order') ?? '')));
        foreach (DigitalTemplates::all() as $template) {
            $this->app->make(TemplateRegistry::class)->register($template);   // the messages this module sends; staff change their words
        }
        Event::listen(OrderPaid::class, GrantEntitlementsForPaidOrder::class);
        Event::listen(OrderRefunded::class, RevokeForRefundedOrder::class);
        $this->app->make(OutboxRegistry::class)->register(DigitalAssetVersionPublished::class);
        Event::listen(DigitalAssetVersionPublished::class, NotifyOwnersOfNewVersion::class);

        // per person and route, not shared with anything else
        RateLimiter::for('digital-actions', fn (Request $r) => Limit::perMinute(30)->by('digital-actions|'.$r->route()?->getName().'|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('digital-products::admin.menu.assets', 'admin.digital.assets.index', 'digital.view', 55);
        $menu->add('digital-products::admin.menu.entitlements', 'admin.digital.entitlements.index', 'digital.view', 56);
        $menu->add('digital-products::admin.menu.policies', 'admin.digital.policies.index', 'digital.view', 57);
    }
}
