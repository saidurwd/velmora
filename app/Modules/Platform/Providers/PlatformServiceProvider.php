<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Console\DispatchOutboxCommand;
use App\Modules\Platform\Console\MakeModuleCommand;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\RequestContext;
use App\Modules\Platform\Events\OutboxProbe;
use App\Modules\Platform\Http\Middleware\AssignRequestContext;
use App\Modules\Platform\Http\Middleware\EnsureIdempotency;
use App\Modules\Platform\Http\Middleware\SecureHeaders;
use App\Modules\Platform\Listeners\AcknowledgeOutboxProbe;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Platform\Support\SystemClock;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PlatformServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Platform';
    }

    public function register(): void
    {
        $this->commands([MakeModuleCommand::class, DispatchOutboxCommand::class]);

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
        $router->aliasMiddleware('secure.headers', SecureHeaders::class);

        $this->app->make(OutboxRegistry::class)->register(OutboxProbe::class);
        Event::listen(OutboxProbe::class, [AcknowledgeOutboxProbe::class, 'handle']);

        $this->defineRateLimiters();
        $this->assertSafeConfiguration();
    }

    /** Rate-limit tiers from API_ARCHITECTURE §2. Per-identifier limits arrive with the auth modules (Phase 2). */
    private function defineRateLimiters(): void
    {
        $by = fn (Request $r): string => (string) ($r->user()?->getAuthIdentifier() ?? $r->ip());

        RateLimiter::for('public-read', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('search', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('otp', fn (Request $r) => [Limit::perMinutes(10, 3)->by('otp-ip-min:'.$r->ip()), Limit::perHour(10)->by('otp-ip-hour:'.$r->ip())]);
        RateLimiter::for('user-read', fn (Request $r) => Limit::perMinute(120)->by($by($r)));
        RateLimiter::for('write', fn (Request $r) => Limit::perMinute(60)->by($by($r)));
        RateLimiter::for('critical', fn (Request $r) => Limit::perMinute(10)->by($by($r)));
        RateLimiter::for('webhook', fn (Request $r) => Limit::perMinute(300)->by($r->ip()));
    }

    /** Fail fast on unsafe production settings (SECURITY checklist). */
    public function assertSafeConfiguration(): void
    {
        if (! $this->app->environment('production', 'staging')) {
            return;
        }

        $problems = [];

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG must be false';
        }

        if (empty(config('app.key'))) {
            $problems[] = 'APP_KEY must be set';
        }

        if (! config('session.secure')) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true';
        }

        if ($problems !== []) {
            throw new \RuntimeException('Unsafe configuration for '.$this->app->environment().': '.implode('; ', $problems).'.');
        }
    }
}
