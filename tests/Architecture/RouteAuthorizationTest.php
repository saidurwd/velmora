<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/*
 * Deny-by-default (Phase 2 gate). Every route must carry an explicit authorization decision:
 *   - admin routes: `auth:admin` + `permission:*` (or be listed below with the reason it is exempt)
 *   - customer routes: `auth:web` (or be a listed public page)
 * A new route that is neither protected nor listed here FAILS this test.
 */

/** Public customer-facing URIs (any method) and why they need no login. */
const PUBLIC_WEB = [
    '/' => 'locale redirect',
    '{locale}' => 'storefront home',
    '{locale}/pages/{slug}' => 'published CMS pages (drafts and scheduled pages return 404)',
    '{locale}/dev/components' => 'component library (not registered in production)',
    'healthz' => 'liveness probe', 'readyz' => 'readiness probe',
    '{locale}/locale' => 'language switch (validated, throttled)',
    '{locale}/login' => 'sign-in', '{locale}/register' => 'sign-up', '{locale}/register/check-your-email' => 'sign-up confirmation',
    '{locale}/login/phone' => 'phone sign-in', '{locale}/login/phone/verify' => 'phone code entry (needs session state)',
    '{locale}/forgot-password' => 'forgot password', '{locale}/reset-password/{token}' => 'reset form', '{locale}/reset-password' => 'reset submit (token)',
    '{locale}/email/verify/{id}/{hash}' => 'emailed signed link (signature + single use)',
    '{fallbackPlaceholder}' => 'renders the 404 page',
];

/** Admin URIs reachable without a staff session. */
const ADMIN_PUBLIC = [
    'admin/login' => 'staff sign-in (password step)',
    'admin/forgot-password' => 'staff forgot password', 'admin/reset-password/{token}' => 'staff reset form', 'admin/reset-password' => 'staff reset submit (token)',
    'admin/mfa/enrol' => 'requires the pending password step (admin.pending)', 'admin/mfa/challenge' => 'requires the pending password step (admin.pending)',
];

/** Signed-in staff, no specific permission: strictly about the person themselves. */
const ADMIN_AUTH_ONLY = [
    'admin/logout' => 'ends own session', 'admin/account/sessions' => 'own device list', 'admin/account/sessions/revoke-others' => 'own devices only',
    'admin/mfa/recovery-codes' => 'shown once to the signed-in person',
];

/** @return list<LaravelRoute> */
function appRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())->filter(function (LaravelRoute $route) {
        $uri = $route->uri();

        // framework / vendor routes with their own protection: Horizon (viewHorizon gate), Laravel health, storage, Sanctum
        return ! preg_match('#^(horizon|up$|storage/|sanctum/|_ignition|livewire)#', $uri) && ! str_starts_with($uri, '_t') && ! str_contains($uri, '_test');
    })->values()->all();
}

/** @return list<string> */
function mw(LaravelRoute $route): array
{
    return array_map('strval', $route->gatherMiddleware());
}

function hasMw(LaravelRoute $route, string $prefix): bool
{
    return collect(mw($route))->contains(fn (string $m) => $m === $prefix || str_starts_with($m, $prefix.':') || str_starts_with($m, $prefix));
}

it('has an explicit authorization decision for every admin route', function () {
    $offenders = [];

    foreach (appRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'admin')) {
            continue;
        }

        if (array_key_exists($route->uri(), ADMIN_PUBLIC)) {
            continue;
        }

        if (! hasMw($route, 'auth:admin')) {
            $offenders[] = "{$route->methods()[0]} /{$route->uri()} lacks auth:admin";
        } elseif (! hasMw($route, 'permission:') && ! array_key_exists($route->uri(), ADMIN_AUTH_ONLY)) {
            $offenders[] = "{$route->methods()[0]} /{$route->uri()} lacks permission:*";
        }
    }

    expect($offenders)->toBe([]);
});

it('has an explicit authorization decision for every customer-facing route', function () {
    $offenders = [];

    foreach (appRoutes() as $route) {
        if (str_starts_with($route->uri(), 'admin')) {
            continue;
        }

        if (array_key_exists($route->uri(), PUBLIC_WEB)) {
            continue;
        }

        if (str_starts_with($route->uri(), 'api/')) {
            if (! hasMw($route, 'auth:sanctum')) {
                $offenders[] = "API {$route->methods()[0]} /{$route->uri()} lacks auth:sanctum";
            }

            continue;
        }

        if (! hasMw($route, 'auth:web')) {
            $offenders[] = "{$route->methods()[0]} /{$route->uri()} is neither protected nor listed as public";
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the exemption lists free of stale entries', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->map(fn (LaravelRoute $r) => $r->uri())->all();

    foreach (array_merge(array_keys(PUBLIC_WEB), array_keys(ADMIN_PUBLIC), array_keys(ADMIN_AUTH_ONLY)) as $listed) {
        expect($uris)->toContain($listed);
    }
});

it('demands the MFA pending state on the exempt MFA pages', function () {
    foreach (['admin.mfa.enrol', 'admin.mfa.challenge'] as $name) {
        expect(hasMw(Route::getRoutes()->getByName($name), 'admin.pending'))->toBeTrue("{$name} must require admin.pending");
    }
});

it('enforces the decision at runtime: guests, customers and role-less staff are all refused on every admin route', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $customer = User::factory()->create();
    $roleless = User::factory()->staff()->create(['email' => 'nobody@example.com']);

    $checked = 0;
    foreach (appRoutes() as $route) {
        $name = (string) $route->getName();
        if (! str_starts_with($route->uri(), 'admin') || ! hasMw($route, 'permission:')) {
            continue;
        }

        $uri = '/'.preg_replace_callback('/\{([^}]+)\}/', fn (array $m) => preg_match('/^[\w|]+$/', $route->wheres[$m[1]] ?? '') ? explode('|', $route->wheres[$m[1]])[0] : (isset($route->wheres[$m[1]]) ? '1' : 'x'), $route->uri());
        $method = $route->methods()[0];

        auth()->forgetGuards();
        $this->call($method, $uri)->assertRedirect('/admin/login');                       // guest

        $this->actingAs($customer, 'web')->call($method, $uri)->assertRedirect('/admin/login'); // customer session

        auth()->forgetGuards();
        $this->actingAs($roleless, 'admin')->call($method, $uri)->assertForbidden();      // staff without the permission: 403, never 404/200

        $checked++;
    }

    expect($checked)->toBeGreaterThanOrEqual(12);
});

it('enforces the decision at runtime on customer routes: guests are sent to sign in', function () {
    foreach (appRoutes() as $route) {
        if (str_starts_with($route->uri(), 'admin') || ! hasMw($route, 'auth:web') || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        auth()->forgetGuards();
        $uri = '/'.preg_replace('/\{[^}]+\}/', 'x', str_replace('{locale}', 'en', $route->uri()));

        $this->get($uri)->assertRedirect('/en/login');
    }
});
