<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Providers;

use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Shipping\Contracts\ShippingQuoter;
use App\Modules\Shipping\Services\DatabaseShippingQuoter;

class ShippingServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Shipping';
    }

    public function register(): void
    {
        $this->app->bind(ShippingQuoter::class, DatabaseShippingQuoter::class);
    }
}
