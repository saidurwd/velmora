<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Providers;

use App\Modules\Platform\Support\ModuleServiceProvider;

class AnalyticsServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Analytics';
    }
}
