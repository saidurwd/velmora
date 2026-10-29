<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Providers;

use App\Modules\IdentityAccess\Auth\AccountTypeUserProvider;
use App\Modules\IdentityAccess\Auth\RecentAuth;
use App\Modules\IdentityAccess\Console\CreateSuperAdminCommand;
use App\Modules\IdentityAccess\Console\RbacMatrixCommand;
use App\Modules\IdentityAccess\Contracts\PhoneConfirmation;
use App\Modules\IdentityAccess\Events\UserRegistered;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Http\Middleware\RequirePendingStaffLogin;
use App\Modules\IdentityAccess\Http\Middleware\RequirePermission;
use App\Modules\IdentityAccess\Http\Middleware\RequireRecentAuth;
use App\Modules\IdentityAccess\Listeners\SaveUserLocale;
use App\Modules\IdentityAccess\Listeners\TrackSession;
use App\Modules\IdentityAccess\Mfa\PendingStaffLogin;
use App\Modules\IdentityAccess\Otp\CheckoutPhoneConfirmation;
use App\Modules\IdentityAccess\Permissions\SuperAdminBypass;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Events\LocaleChosen;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\ModuleServiceProvider;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

class IdentityAccessServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SuperAdminBypass::class);
        $this->app->bind(PhoneConfirmation::class, CheckoutPhoneConfirmation::class);
        $this->commands([CreateSuperAdminCommand::class, RbacMatrixCommand::class]);
        $this->app->scoped(RecentAuth::class, fn ($app) => new RecentAuth($app['session.store'], $app->make(Clock::class)));
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
        $this->app->make(Router::class)->aliasMiddleware('permission', RequirePermission::class);
        $this->app->make(Router::class)->aliasMiddleware('recent.auth', RequireRecentAuth::class);

        // Super Admin passes catalogue permissions explicitly; sensitive uses are audited (see SuperAdminBypass).
        Gate::before($this->app->make(SuperAdminBypass::class));

        // roles are addressed by name in admin URLs (Spatie roles have no public id)
        Route::bind('role', fn (string $value) => Role::query()->where('guard_name', 'admin')->where('name', $value)->firstOrFail());

        $menu = $this->app->make(AdminMenu::class);
        $menu->add('identity-access::admin.menu.staff', 'admin.staff.index', 'staff.view', 80);
        $menu->add('identity-access::admin.menu.roles', 'admin.roles.index', 'roles.view', 81);
        $menu->add('identity-access::admin.menu.sessions', 'admin.sessions', null, 99);

        Auth::provider('account_type', fn ($app, array $config) => new AccountTypeUserProvider(
            $app['hash'],
            $config['model'],
            $config['account_type'],
        ));
    }
}
