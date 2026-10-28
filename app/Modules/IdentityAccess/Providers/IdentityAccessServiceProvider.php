<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Providers;

use App\Modules\IdentityAccess\Auth\AccountTypeUserProvider;
use App\Modules\IdentityAccess\Events\UserRegistered;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Http\Middleware\RequirePendingStaffLogin;
use App\Modules\IdentityAccess\Listeners\SaveUserLocale;
use App\Modules\IdentityAccess\Listeners\TrackSession;
use App\Modules\IdentityAccess\Mfa\PendingStaffLogin;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Events\LocaleChosen;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\ModuleServiceProvider;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

class IdentityAccessServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PendingStaffLogin::class, fn ($app) => new PendingStaffLogin($app['session.store'], $app->make(Clock::class)));
    }

    protected function module(): string
    {
        return 'IdentityAccess';
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(LocaleChosen::class, [SaveUserLocale::class, 'handle']);
        Event::listen(Login::class, [TrackSession::class, 'onLogin']);
        Event::listen(Logout::class, [TrackSession::class, 'onLogout']);

        $this->app->make(OutboxRegistry::class)->register(UserRegistered::class);
        $this->app->make(Router::class)->aliasMiddleware('session.policy', EnforceSessionPolicy::class);
        $this->app->make(Router::class)->aliasMiddleware('admin.pending', RequirePendingStaffLogin::class);

        Auth::provider('account_type', fn ($app, array $config) => new AccountTypeUserProvider(
            $app['hash'],
            $config['model'],
            $config['account_type'],
        ));
    }
}
