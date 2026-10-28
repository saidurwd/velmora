<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Console\MakeModuleCommand;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\RequestContext;
use App\Modules\Platform\Http\Middleware\AssignRequestContext;
use App\Modules\Platform\Http\Middleware\EnsureIdempotency;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Platform\Support\SystemClock;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

class PlatformServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Platform';
    }

    public function register(): void
    {
        $this->commands([MakeModuleCommand::class]);

        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(OutboxRegistry::class);

        // One RequestContext per request / queued job / console run.
        $this->app->scoped(RequestContext::class, fn () => new RequestContext(
            requestId: strtolower((string) Str::ulid()),
            actor: Actor::system($this->app->runningInConsole() ? 'console' : 'system'),
            locale: app()->getLocale(),
        ));
    }

    public function boot(): void
    {
        parent::boot();

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('idempotent', EnsureIdempotency::class);
        $router->aliasMiddleware('request.context', AssignRequestContext::class);
    }
}
