<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Console\ApiCollectionCommand;
use App\Modules\Platform\Console\ApiCompatCommand;
use App\Modules\Platform\Console\DispatchOutboxCommand;
use App\Modules\Platform\Console\MakeModuleCommand;
use App\Modules\Platform\Console\PreflightCommand;
use App\Modules\Platform\Contracts\AuditPeople;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\DTOs\RequestContext;
use App\Modules\Platform\Events\OutboxProbe;
use App\Modules\Platform\Http\Middleware\AssignRequestContext;
use App\Modules\Platform\Http\Middleware\ConditionalGet;
use App\Modules\Platform\Http\Middleware\EnsureIdempotency;
use App\Modules\Platform\Http\Middleware\MarkDeprecated;
use App\Modules\Platform\Http\Middleware\SecureHeaders;
use App\Modules\Platform\Http\Middleware\SetLocale;
use App\Modules\Platform\Http\Resources\MoneyData;
use App\Modules\Platform\Listeners\AcknowledgeOutboxProbe;
use App\Modules\Platform\Listeners\RecordScheduledTask;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\Api\ApiConfig;
use App\Modules\Platform\Support\Branding;
use App\Modules\Platform\Support\BulkActions;
use App\Modules\Platform\Support\BusinessProfile;
use App\Modules\Platform\Support\DashboardRegistry;
use App\Modules\Platform\Support\Fmt;
use App\Modules\Platform\Support\IntegrationCredentials;
use App\Modules\Platform\Support\Integrations\MailHandshakeTest;
use App\Modules\Platform\Support\IntegrationTests;
use App\Modules\Platform\Support\Integrity\StuckOutboxCheck;
use App\Modules\Platform\Support\IntegrityRegistry;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Platform\Support\NullAuditPeople;
use App\Modules\Platform\Support\PageSeo;
use App\Modules\Platform\Support\SavedFilterScreens;
use App\Modules\Platform\Support\SearchProviders;
use App\Modules\Platform\Support\Settings;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Platform\Support\SystemClock;
use Closure;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

class PlatformServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Platform';
    }

    public function register(): void
    {
        $this->commands([MakeModuleCommand::class, DispatchOutboxCommand::class, ApiCompatCommand::class, ApiCollectionCommand::class, PreflightCommand::class]);

        // Names on the audit and security screens; the module that owns accounts binds the real one (IdentityAccess).
        $this->app->bindIf(AuditPeople::class, NullAuditPeople::class);

        // The API document is a committed file (docs/api/openapi.json), not a web page: no documentation routes on any server.
        if (class_exists(Scramble::class)) {
            Scramble::ignoreDefaultRoutes();
        }

        // Route::localized(fn) -> /{locale}/... for every supported language (BD-33).
        Route::macro('localized', function (Closure $routes): void {
            Route::prefix('{locale}')->whereIn('locale', config('app.supported_locales'))->group($routes);
        });

        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(OutboxRegistry::class);
        $this->app->singleton(IntegrityRegistry::class);
        $this->app->singleton(IntegrationTests::class);
        $this->app->singleton(DashboardRegistry::class);
        $this->app->singleton(SearchProviders::class);
        $this->app->singleton(SavedFilterScreens::class);
        $this->app->singleton(BulkActions::class);
        $this->app->singleton(IntegrationCredentials::class);
        $this->app->singleton(AdminMenu::class);
        $this->app->singleton(Branding::class);
        $this->app->scoped(PageSeo::class);
        $this->app->singleton(StorefrontCache::class);
        $this->app->singleton(ApiConfig::class);

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

        Event::listen(RouteMatched::class, fn () => $this->app->make(PageSeo::class)->reset());
        Paginator::defaultView('platform::pagination');
        Paginator::defaultSimpleView('platform::pagination');

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('idempotent', EnsureIdempotency::class);
        $router->aliasMiddleware('request.context', AssignRequestContext::class);
        $router->aliasMiddleware('secure.headers', SecureHeaders::class);
        $router->aliasMiddleware('locale', SetLocale::class);
        $router->aliasMiddleware('etag', ConditionalGet::class);
        $router->aliasMiddleware('deprecated', MarkDeprecated::class);

        $this->app->make(OutboxRegistry::class)->register(OutboxProbe::class);
        Event::listen(OutboxProbe::class, [AcknowledgeOutboxProbe::class, 'handle']);

        $this->app->make(AdminMenu::class)->add('platform::admin.dashboard', 'admin.dashboard', 'dashboard.view', 10);
        $this->app->make(AdminMenu::class)->add('platform::settings.menu', 'admin.settings.index', 'settings.view', 89);
        $this->app->make(AdminMenu::class)->add('platform::audit.menu', 'admin.audit.index', 'audit.view', 91);
        $menu = $this->app->make(AdminMenu::class);
        // Security: what people did and how they signed in
        $menu->add('platform::security.menu.activity', 'admin.security.activity', 'audit.view', 92);
        $menu->add('platform::security.menu.events', 'admin.security.events', 'audit.view', 93);
        $menu->add('platform::security.menu.logins', 'admin.security.logins', 'audit.view', 94);
        // System: is it working, and what is it
        $menu->add('platform::ops.menu', 'admin.ops.index', 'system.view', 92);
        $menu->add('platform::ops.menu_items.health', 'admin.ops.health', 'system.view', 93);
        $menu->add('platform::ops.menu_items.queues', 'admin.ops.queues', 'queues.view', 94);
        $menu->add('platform::ops.menu_items.scheduler', 'admin.ops.scheduler', 'system.view', 95);
        $menu->add('platform::ops.menu_items.failed', 'admin.ops.failed', 'queues.view', 96);
        $menu->add('platform::ops.menu_items.integrity', 'admin.ops.integrity', 'system.view', 97);
        $menu->add('platform::ops.menu_items.about', 'admin.ops.about', 'system.view', 98);
        $this->app->make(IntegrityRegistry::class)->add(StuckOutboxCheck::class);
        $this->app->make(IntegrationTests::class)->add('mail', MailHandshakeTest::class);

        // saved e-mail settings are applied when Laravel first builds its mail manager; if they cannot be read the server's own settings stay in force
        $this->app->resolving('mail.manager', function (): void {
            try {
                $this->app->make(IntegrationCredentials::class)->applyMail();
            } catch (Throwable $e) {
                Log::warning('integrations.mail_overrides_skipped', ['kind' => $e::class]);
            }
        });

        // keep one row per scheduled task: when it last started and finished
        $recorder = RecordScheduledTask::class;
        Event::listen(ScheduledTaskStarting::class, [$recorder, 'starting']);
        Event::listen(ScheduledTaskFinished::class, [$recorder, 'finished']);
        Event::listen(ScheduledTaskFailed::class, [$recorder, 'failed']);
        Event::listen(ScheduledTaskSkipped::class, [$recorder, 'skipped']);

        $this->defineRateLimiters();
        $this->defineApiConfig();
        $this->defineApiDocs();
        $this->assertSafeConfiguration();
    }

    /** The public API document covers /api/v1 except the provider callbacks (webhooks are not part of the public API). Scramble is a dev tool: absent in production. */
    private function defineApiDocs(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        // relative server, so the committed document is the same on every machine (the app's own base URL is its configuration)
        Scramble::configure()->afterOpenApiGenerated(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer')->setDescription('The access_token from sign-in. Send `Authorization: Bearer <access_token>`.'));
            $openApi->servers = [Server::make('/api/v1')->setDescription('This server')];
        });
        Scramble::configure()->routes(fn (\Illuminate\Routing\Route $route): bool => str_starts_with($route->uri(), 'api/v1/') && ! str_contains($route->uri(), '/webhooks/'));
    }

    /** The sections of GET /config that belong to Platform: languages, money, how prices are shown, app versions, how to reach the shop. */
    private function defineApiConfig(): void
    {
        $config = $this->app->make(ApiConfig::class);
        $config->section('languages', fn (): array => [
            'default' => (string) config('app.default_locale'),
            'supported' => array_map(fn (string $code): array => ['code' => $code, 'name' => (string) config("velmora.locale_labels.{$code}", $code)], array_values(config('app.supported_locales'))),
        ]);
        $config->section('currency', function (): array {
            $code = (string) $this->app->make(Settings::class)->get('business.currency', 'BDT');

            return ['code' => $code, 'symbol' => trim(str_replace('0', '', Fmt::money(Money::zero($code)))), 'decimals' => 2, 'example' => MoneyData::from(Money::of(125000, $code))];
        });
        $config->section('tax', function (): array {
            $settings = $this->app->make(Settings::class);

            return ['enabled' => (bool) $settings->get('tax.enabled', false), 'prices_include_tax' => (bool) $settings->get('tax.prices_include_tax', true)];
        });
        $config->section('app', fn (): array => [
            'min_supported_version' => config('api.app.min_supported_version'),
            'latest_version' => config('api.app.latest_version'),
            'store_urls' => config('api.app.store_urls'),
        ]);
        $config->section('shop', function (): array {
            $profile = $this->app->make(BusinessProfile::class);

            return ['name' => $profile->legalName(app()->getLocale()), 'phone' => $profile->phone(), 'email' => $profile->email()];
        });
    }

    /** 10 in production whatever the setting says: the setting exists so the browser tests (one address, many sign-ins) can raise it, never to loosen a live shop. */
    private function authTriesPerMinute(): int
    {
        $setting = max(1, (int) config('security.auth_throttle_per_minute', 10));

        return app()->environment('production') ? min(10, $setting) : $setting;
    }

    /** Rate-limit tiers from API_ARCHITECTURE §2. Per-identifier limits arrive with the auth modules (Phase 2). */
    private function defineRateLimiters(): void
    {
        $by = fn (Request $r): string => (string) ($r->user()?->getAuthIdentifier() ?? $r->ip());

        // Every staff screen that CHANGES something (anything but a read): 120 a minute per staff member (per address while not yet signed in). Reads are not limited here.
        RateLimiter::for('admin-write', fn (Request $r) => $r->isMethodSafe() ? Limit::none() : Limit::perMinute(120)->by('admin-write|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));
        RateLimiter::for('public-read', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('search', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute($this->authTriesPerMinute())->by($r->ip()));
        // Token refresh has its own, higher limit per address: many phones share one address on a mobile carrier, and a refresh token is 256 random bits that work once (P11-9), so guessing one is pointless.
        RateLimiter::for('refresh', fn (Request $r) => Limit::perMinute(60)->by('refresh|'.$r->ip()));
        RateLimiter::for('otp', fn (Request $r) => [Limit::perMinutes(10, (int) config('otp.throttle_per_ten_minutes'))->by('otp-ip-min:'.$r->ip()), Limit::perHour((int) config('otp.throttle_per_hour'))->by('otp-ip-hour:'.$r->ip())]);
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
