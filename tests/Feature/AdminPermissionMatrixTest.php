<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Permissions\PermissionCatalogue;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/*
 * Phase 10 gate: the permission matrix over EVERY admin route, built from the routes themselves so it cannot drift.
 * For each route that names permissions (`permission:a,b` = any of them):
 *   - staff holding exactly ONE of the named permissions get past the permission gate (never 403, never a login redirect);
 *   - staff holding some other permission (a near miss) get 403;
 * (guests, customers and staff with no permission are covered by tests/Architecture/RouteAuthorizationTest.)
 */

/**
 * Routes that cannot be called by the plain matrix, and why. A signed link is refused by the `signed` middleware
 * before the permission is looked at, so the matrix would learn nothing; each is covered by its own test.
 */
const MATRIX_EXEMPT = [
    'admin/reports/exports/{publicId}/download' => 'signed, expiring link: an unsigned call is refused 403 first (async export lifecycle tests cover it, including the permission and expiry)',
];

/** @return list<array{route: LaravelRoute, perms: list<string>, uri: string, method: string}> */
function adminPermissionRoutes(): array
{
    $rows = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'admin')) {
            continue;
        }
        if (array_key_exists($route->uri(), MATRIX_EXEMPT)) {
            continue;
        }
        $perms = [];
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'permission:')) {
                $perms = array_merge($perms, explode(',', substr($m, strlen('permission:'))));
            }
        }
        if ($perms === []) {
            continue;
        }
        $uri = '/'.preg_replace_callback('/\{([^}]+)\}/', fn (array $m) => preg_match('/^[\w|]+$/', $route->wheres[$m[1]] ?? '') ? explode('|', $route->wheres[$m[1]])[0] : (isset($route->wheres[$m[1]]) ? '1' : 'x'), $route->uri());
        $rows[] = ['route' => $route, 'perms' => array_values(array_unique($perms)), 'uri' => $uri, 'method' => $route->methods()[0]];
    }

    return $rows;
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
});

it('names only permissions that exist (an unknown name would silently lock everyone out)', function () {
    $unknown = [];
    foreach (adminPermissionRoutes() as $r) {
        foreach ($r['perms'] as $p) {
            if (! PermissionCatalogue::exists($p)) {
                $unknown[] = "{$r['method']} {$r['uri']} names {$p}";
            }
        }
    }

    expect($unknown)->toBe([]);
});

it('lets staff holding one named permission through every admin route, and refuses a near miss', function () {
    $routes = adminPermissionRoutes();
    expect(count($routes))->toBeGreaterThan(100);

    // one staff member per permission, made once
    $all = collect($routes)->pluck('perms')->flatten()->unique()->values()->all();
    $staff = [];
    foreach ($all as $p) {
        $staff[$p] = catalogStaff([$p]);
    }

    $problems = [];
    foreach ($routes as $r) {
        // a permission that is NOT one of this route's, to play the near miss
        $other = collect($all)->first(fn (string $p) => ! in_array($p, $r['perms'], true));

        foreach ($r['perms'] as $p) {
            auth()->forgetGuards();
            $res = $this->actingAs($staff[$p], 'admin')->call($r['method'], $r['uri']);
            $status = $res->getStatusCode();
            if ($status === 403 || ($status === 302 && str_contains((string) $res->headers->get('Location'), '/admin/login'))) {
                $problems[] = "{$r['method']} {$r['uri']} refused the holder of {$p} ({$status})";
            }
        }

        auth()->forgetGuards();
        $status = $this->actingAs($staff[$other], 'admin')->call($r['method'], $r['uri'])->getStatusCode();
        if ($status !== 403) {
            $problems[] = "{$r['method']} {$r['uri']} gave {$status} to the holder of only {$other} (expected 403)";
        }
    }

    expect($problems)->toBe([]);
});

it('keeps the exemption list free of stale entries and of unsigned routes', function () {
    foreach (array_keys(MATRIX_EXEMPT) as $uri) {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn (LaravelRoute $r) => $r->uri() === $uri);
        expect($route)->not->toBeNull()
            ->and(collect($route->gatherMiddleware())->contains('signed'))->toBeTrue("{$uri} is exempt only because it is signed");
    }
});
