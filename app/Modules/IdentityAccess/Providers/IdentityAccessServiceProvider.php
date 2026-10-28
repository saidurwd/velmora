<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Providers;

use App\Modules\IdentityAccess\Auth\AccountTypeUserProvider;
use App\Modules\IdentityAccess\Listeners\SaveUserLocale;
use App\Modules\Platform\Events\LocaleChosen;
use App\Modules\Platform\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

class IdentityAccessServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'IdentityAccess';
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(LocaleChosen::class, [SaveUserLocale::class, 'handle']);

        Auth::provider('account_type', fn ($app, array $config) => new AccountTypeUserProvider(
            $app['hash'],
            $config['model'],
            $config['account_type'],
        ));
    }
}
