<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Console\StockProbeCommand;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Inventory\Contracts\ShipmentStock;
use App\Modules\Inventory\Contracts\StockInitialiser;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Inventory\Events\StockLow;
use App\Modules\Inventory\Services\DatabaseAvailability;
use App\Modules\Inventory\Services\LedgerStockInitialiser;
use App\Modules\Inventory\Services\OrderShipmentStock;
use App\Modules\Inventory\Services\OrderStockReservations;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;

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
        $this->app->bind(StockInitialiser::class, LedgerStockInitialiser::class);
        $this->commands([StockProbeCommand::class]);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(OutboxRegistry::class)->register(StockLow::class);
        $this->app->make(AdminMenu::class)->add('inventory::admin.menu.stock', 'admin.inventory.index', 'inventory.view', 50);
    }
}
