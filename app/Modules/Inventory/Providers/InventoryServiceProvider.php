<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Modules\Platform\Support\ModuleServiceProvider;

class InventoryServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Inventory';
    }
}
