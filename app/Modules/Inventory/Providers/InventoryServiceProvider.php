<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Console\StockOpsProbeCommand;
use App\Modules\Inventory\Console\StockProbeCommand;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Inventory\Contracts\ReturnStock;
use App\Modules\Inventory\Contracts\ShipmentStock;
use App\Modules\Inventory\Contracts\StockInitialiser;
use App\Modules\Inventory\Contracts\StockReportFigures;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Inventory\Events\StockLow;
use App\Modules\Inventory\Services\DatabaseAvailability;
use App\Modules\Inventory\Services\DatabaseStockReportFigures;
use App\Modules\Inventory\Services\LedgerReturnStock;
use App\Modules\Inventory\Services\LedgerStockInitialiser;
use App\Modules\Inventory\Services\OrderShipmentStock;
use App\Modules\Inventory\Services\OrderStockReservations;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class InventoryServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Inventory';
    }

    public function register(): void
    {
        $this->app->bind(Availability::class, DatabaseAvailability::class);
        $this->app->bind(StockReservations::class, OrderStockReservations::class);
        $this->app->bind(ShipmentStock::class, OrderShipmentStock::class);
        $this->app->bind(ReturnStock::class, LedgerReturnStock::class);
        $this->app->bind(StockInitialiser::class, LedgerStockInitialiser::class);
        $this->app->bind(StockReportFigures::class, DatabaseStockReportFigures::class);
        $this->commands([StockProbeCommand::class, StockOpsProbeCommand::class]);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(OutboxRegistry::class)->register(StockLow::class);
        RateLimiter::for('inventory-actions', fn (Request $r) => Limit::perMinute(60)->by('inventory-actions|'.$r->route()?->getName().'|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('inventory::admin.menu.stock', 'admin.inventory.index', 'inventory.view', 50);
        $menu->add('inventory::screens.menu.transfers', 'admin.inventory.transfers.index', 'inventory.view', 51);
        $menu->add('inventory::screens.menu.counts', 'admin.inventory.counts.index', 'inventory.view', 52);
        $menu->add('inventory::screens.menu.low', 'admin.inventory.reports.low', 'inventory.view', 53);
        $menu->add('inventory::screens.menu.aging', 'admin.inventory.reports.aging', 'inventory.view', 54);
    }
}
