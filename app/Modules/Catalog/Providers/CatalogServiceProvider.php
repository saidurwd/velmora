<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Platform\Support\ModuleServiceProvider;

class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Catalog';
    }
}
