<?php

declare(strict_types=1);

namespace App\Modules\Customers\Providers;

use App\Modules\Customers\Contracts\AddressBook;
use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Customers\Contracts\Geography;
use App\Modules\Customers\Listeners\CreateCustomerProfile;
use App\Modules\Customers\Services\DatabaseGeography;
use App\Modules\Customers\Services\EloquentAddressBook;
use App\Modules\Customers\Services\EloquentCustomerProfiles;
use App\Modules\IdentityAccess\Events\UserRegistered;
use App\Modules\Platform\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class CustomersServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Customers';
    }

    public function register(): void
    {
        $this->app->bind(CustomerProfiles::class, EloquentCustomerProfiles::class);
        $this->app->bind(Geography::class, DatabaseGeography::class);
        $this->app->bind(AddressBook::class, EloquentAddressBook::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(UserRegistered::class, [CreateCustomerProfile::class, 'handle']);
    }
}
