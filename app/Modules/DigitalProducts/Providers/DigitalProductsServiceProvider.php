<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Providers;

use App\Modules\DigitalProducts\Console\DigitalProbeCommand;
use App\Modules\DigitalProducts\Listeners\GrantEntitlementsForPaidOrder;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

class DigitalProductsServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'DigitalProducts';
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([DigitalProbeCommand::class]);
        Event::listen(OrderPaid::class, GrantEntitlementsForPaidOrder::class);

        // per person and route, not shared with anything else
        RateLimiter::for('digital-actions', fn (Request $r) => Limit::perMinute(30)->by('digital-actions|'.$r->route()?->getName().'|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));

        $this->app->make(AdminMenu::class)->add('digital-products::admin.menu.assets', 'admin.digital.assets.index', 'digital.view', 55);
    }
}
