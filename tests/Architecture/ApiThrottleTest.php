<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
 * Abuse controls for the app's API (Phase 11, slice 8).
 *  1. Every /api/v1 route is rate-limited.
 *  2. A route a signed-in customer calls is limited PER CUSTOMER, not per address. Many phones share one address (mobile carriers put thousands of customers behind a
 *     few), so an address limit would lock honest customers out, and a person changing address would escape it. The limiters named below are per address ON PURPOSE: they guard
 *     the doors where nobody is signed in yet (sign-in, codes, public reads, webhooks).
 */

const IP_BY_DESIGN = ['public-read', 'search', 'auth', 'otp', 'webhook', 'courier-webhooks'];

/** @return list<LaravelRoute> */
function apiRoutes(): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (LaravelRoute $r): bool => str_starts_with($r->uri(), 'api/v1/')));
}

function throttleOf(LaravelRoute $route): ?string
{
    foreach ($route->gatherMiddleware() as $m) {
        if (is_string($m) && str_starts_with($m, 'throttle:')) {
            return substr($m, 9);
        }
    }

    return null;
}

/** @return list<string> the keys the limiter gives this request */
function limiterKeys(string $name, LaravelRoute $route, string $ip, int $userId): array
{
    $request = Request::create('/'.$route->uri(), 'POST', server: ['REMOTE_ADDR' => $ip]);
    $user = new class($userId)
    {
        public function __construct(private readonly int $id) {}

        public function getAuthIdentifier(): int
        {
            return $this->id;
        }
    };
    $request->setUserResolver(fn (?string $guard = null) => in_array($guard, [null, 'sanctum'], true) ? $user : null);   // an app request has a token, never a website session
    $bound = clone $route;   // the router's own route stays untouched
    (new ReflectionProperty($bound, 'parameters'))->setValue($bound, array_fill_keys($route->parameterNames(), '1'));
    $request->setRouteResolver(fn () => $bound);
    $limits = Arr::wrap(RateLimiter::limiter($name)($request));

    return array_map(fn ($l): string => (string) $l->key, $limits);
}

it('rate-limits every route of the API', function () {
    $bare = array_map(fn (LaravelRoute $r) => implode('|', $r->methods()).' '.$r->uri(), array_filter(apiRoutes(), fn (LaravelRoute $r) => throttleOf($r) === null));

    expect(apiRoutes())->not->toBeEmpty()->and($bare)->toBe([]);
});

it('only uses limiters that exist', function () {
    foreach (apiRoutes() as $route) {
        expect(RateLimiter::limiter((string) throttleOf($route)))->not->toBeNull('no limiter named '.throttleOf($route));
    }
});

it('limits a signed-in customer\'s routes per customer: the same key from any address, a different key for another customer', function () {
    $checked = 0;
    foreach (apiRoutes() as $route) {
        $name = (string) throttleOf($route);
        $signedIn = in_array('auth:sanctum', $route->gatherMiddleware(), true);
        if (! $signedIn || in_array($name, IP_BY_DESIGN, true)) {
            continue;
        }

        $same = [limiterKeys($name, $route, '203.0.113.1', 41), limiterKeys($name, $route, '198.51.100.7', 41)];
        $other = limiterKeys($name, $route, '203.0.113.1', 42);
        expect($same[0])->toBe($same[1], $route->uri().' ('.$name.') is limited per address, not per customer')
            ->and($other)->not->toBe($same[0], $route->uri().' ('.$name.') gives two customers the same limit');
        $checked++;
    }

    expect($checked)->toBeGreaterThan(40);
});

it('limits the doors where nobody is signed in yet per address, and those only', function () {
    foreach (apiRoutes() as $route) {
        $name = (string) throttleOf($route);
        if (in_array($name, ['auth', 'otp', 'public-read', 'search'], true) && in_array('auth:sanctum', $route->gatherMiddleware(), true) && ! in_array($route->uri(), ['api/v1/auth/reauth', 'api/v1/auth/reauth/code', 'api/v1/me/email', 'api/v1/me/email/resend-verification', 'api/v1/me/phone/code', 'api/v1/me/phone/confirm'], true)) {
            throw new RuntimeException($route->uri().' is for signed-in customers but limited per address');
        }
    }

    expect(true)->toBeTrue();
});
