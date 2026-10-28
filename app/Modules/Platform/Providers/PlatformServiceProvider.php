<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Console\MakeModuleCommand;
use App\Modules\Platform\Support\ModuleServiceProvider;

class PlatformServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Platform';
    }

    public function register(): void
    {
        $this->commands([MakeModuleCommand::class]);
    }
}
