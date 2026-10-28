<?php

declare(strict_types=1);

namespace App\Modules\Customers\Providers;

use App\Modules\Platform\Support\ModuleServiceProvider;

class CustomersServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Customers';
    }
}
