<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Providers;

use App\Modules\Platform\Support\ModuleServiceProvider;

class NotificationsServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Notifications';
    }
}
