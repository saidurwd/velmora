<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Contracts\MalwareScanner;
use App\Modules\Catalog\Services\Media\NullMalwareScanner;
use App\Modules\Platform\Support\ModuleServiceProvider;

class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Catalog';
    }

    public function register(): void
    {
        $this->app->bind(MalwareScanner::class, NullMalwareScanner::class);
    }
}
