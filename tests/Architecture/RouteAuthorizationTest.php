<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
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
    'robots.txt' => 'crawler rules', 'sitemap.xml' => 'sitemap index', 'sitemaps/{file}' => 'sitemap files (generated names only)',
    '{locale}/search' => 'storefront: search results (throttled)', '{locale}/search/suggest' => 'storefront: autocomplete JSON (throttled)',
    '{locale}/analytics/events' => 'consent-gated first-party events (throttled, validated, DNT/GPC honoured)',
    '{locale}/cart' => 'cart page (own cart only: signed-in user or guest cookie)', '{locale}/cart/items' => 'add to cart (throttled)', '{locale}/cart/items/{variant}' => 'change / remove a line of the own cart (throttled)', '{locale}/cart/coupons' => 'apply a coupon code to the own cart (throttled)', '{locale}/cart/coupons/{code}' => 'remove a code from the own cart', '{locale}/checkout' => 'checkout details (guest allowed; own cart and own saved addresses only)', '{locale}/checkout/review' => 'checkout review and delivery / payment choice (own cart and session draft)', '{locale}/checkout/phone-code' => 'send the SMS code for a large cash order (own session draft; OTP rate limits)', '{locale}/checkout/phone-confirm' => 'check the SMS code (own session draft; attempt-capped)', '{locale}/checkout/place' => 'place the order (own cart; idempotent; throttled critical)', '{locale}/orders/{order}/thanks' => 'order confirmation: signed, expiring link', '{locale}/cart/acknowledge' => 'accept changed prices (own cart)',
    '{locale}/p/{slug}' => 'storefront: product page (live products only)',
    '{locale}/shop' => 'storefront: all products', '{locale}/c/{slug}' => 'storefront: category', '{locale}/brands/{slug}' => 'storefront: brand', '{locale}/collections/{slug}' => 'storefront: collection',
    '{locale}/pages/{slug}' => 'published CMS pages (drafts and scheduled pages return 404)',
    '{locale}/help' => 'help centre: published questions and answers only (hidden ones are never read); no customer data',
    '{locale}/contact' => 'contact page: the shop\'s own phone, email and hours; no customer data',
    '{locale}/dev/components' => 'component library (not registered in production)',
    'healthz' => 'liveness probe', 'readyz' => 'readiness probe',
    '{locale}/locale' => 'language switch (validated, throttled)',
    '{locale}/login' => 'sign-in', '{locale}/register' => 'sign-up', '{locale}/register/check-your-email' => 'sign-up confirmation',
    '{locale}/login/phone' => 'phone sign-in', '{locale}/login/phone/verify' => 'phone code entry (needs session state)',
    '{locale}/forgot-password' => 'forgot password', '{locale}/reset-password/{token}' => 'reset form', '{locale}/reset-password' => 'reset submit (token)',
    '{locale}/email/verify/{id}/{hash}' => 'emailed signed link (signature + single use)',
    '{locale}/pay/return/{payment}/{outcome}' => 'the browser coming back from the payment gateway: signed URL we issued; believes nothing it carries, verifies server to server; no CSRF possible (cross-site POST)',
    '{locale}/pay/order/{order}' => '"Pay now / pay again" link on the order page: signed, expiring URL',
    '{locale}/orders/{order}/track' => 'where is my order: a signed, expiring link the customer was given (the confirmation page, my orders); shows only that order\'s parcel stages, items and our own timeline; no address, phone or courier notes; throttled',
    '{locale}/orders/{order}/receipt' => 'printable order receipt: a signed, expiring link the customer was given (their order page, the confirmation page); built from the stored figures, refused if they do not add up; recipient name and address but no phone; throttled',
    '{locale}/orders/{order}/downloads' => 'a guest\'s downloads: a signed, expiring link we gave the buyer; shows nothing until a code sent to the order\'s phone is entered; throttled',
    '{locale}/orders/{order}/downloads/code' => 'asks for that SMS code: signed link, rate limited by the code service and by throttle:otp',
    '{locale}/orders/{order}/downloads/verify' => 'checks that SMS code (yes or no only): signed link, rate limited',
    '{locale}/orders/{order}/downloads/{entitlement}' => 'a guest\'s download: needs the short session the code opened for that one order, refuses a download of another order, throttled, logged',
    '{locale}/orders/{order}/downloads/{entitlement}/keys' => 'a guest\'s licence keys: needs the short session the code opened for that one order, refuses keys of another order (not found, logged), page is never cached, throttled, each showing audited without the key',
    '{locale}/orders/{order}/bookings' => 'a guest\'s bookings: a signed, expiring link we gave the buyer; shows nothing until a code sent to the order\'s phone is entered; throttled',
    '{locale}/orders/{order}/bookings/code' => 'asks for that SMS code: signed link, rate limited by the code service and by throttle:otp',
    '{locale}/orders/{order}/bookings/verify' => 'checks that SMS code (yes or no only): signed link, rate limited',
    '{locale}/orders/{order}/bookings/{booking}' => 'one booking of a guest\'s order: needs the short session the code opened for that one order; a booking of another order is not found; throttled',
    '{locale}/orders/{order}/bookings/{booking}/cancel' => 'a guest cancels their own booking: the short session for that order, the action re-checks the booking belongs to it, throttled',
    '{locale}/orders/{order}/bookings/{booking}/move' => 'a guest moves their own booking: the short session for that order, the action re-checks the booking belongs to it, throttled',
    '{locale}/orders/{order}/bookings/{booking}/complete' => 'a guest says their own booking is done: the short session for that order, the action re-checks the booking belongs to it, throttled',
    '{locale}/book/{sku}' => 'choosing a time for a service: anyone may look at the free times (nothing is held until a time is pressed); throttled',
    '{locale}/book/{sku}/hold' => 'holds one free time for the visitor and puts the booking in their own cart; the time is re-checked against the real availability, capped per visitor, throttled',
    '{locale}/orders/{order}/account' => 'sign-up from the order confirmation page: signed, expiring URL that only the shopper received (the proof of ownership); creates an account and adds that one order to it; same answer whether or not the address already has an account',
    'api/v1/auth/signup-policies' => 'app sign-up: the policies to accept; no customer data; throttled public-read',
    'api/v1/auth/register' => 'app sign-up with email: generic answer for new and taken addresses; throttled auth; no token is issued',
    'api/v1/auth/login' => 'app password sign-in: the Action throttles per account and address and locks; one answer for every failure; customers only',
    'api/v1/auth/otp/request' => 'app phone-code request: always the same answer for a valid number; per-number, per-address and daily limits',
    'api/v1/auth/otp/verify' => 'app phone-code sign-in: attempt-capped code; one answer for every failure; customers only',
    'api/v1/auth/refresh' => 'swaps a refresh token (the credential itself) for a new pair; single-use, reuse ends the sign-in; throttled auth',
    'api/v1/auth/password/forgot' => 'app password reset request: always the same answer; throttled auth',
    'api/v1/auth/password/reset' => 'app password reset with the emailed token (the credential); ends every sign-in; throttled auth',
    'api/v1/categories' => 'app catalog: the active category tree; no customer data; throttled public-read, ETag', 'api/v1/categories/{slug}' => 'app catalog: one active category; no customer data',
    'api/v1/brands' => 'app catalog: active brands', 'api/v1/collections/{slug}' => 'app catalog: one active collection',
    'api/v1/products' => 'app catalog: live products only, the website\'s own listing service; throttled public-read', 'api/v1/products/{slug}' => 'app catalog: one live product (drafts and scheduled products are 404)', 'api/v1/products/{slug}/related' => 'app catalog: live related products',
    'api/v1/search' => 'app catalog: search over live products; throttled search; the query text is logged without the visitor', 'api/v1/search/autocomplete' => 'app catalog: product name suggestions; never logged; throttled search',
    'api/v1/geo/divisions' => 'reference data: divisions', 'api/v1/geo/districts' => 'reference data: districts of a division', 'api/v1/geo/upazilas' => 'reference data: upazilas of a district',
    'api/v1/pages/{slug}' => 'app: published CMS pages only (drafts and scheduled pages are 404)',
    'api/v1/cart' => 'app cart: the signed-in customer\'s own cart, or the guest holding the X-Cart-Token (hash stored); a wrong bearer token is refused, never read as a guest', 'api/v1/cart/items' => 'app cart: add to the caller\'s own cart (throttled write)', 'api/v1/cart/items/{variant}' => 'app cart: change or remove a line of the caller\'s own cart',
    'api/v1/cart/coupons' => 'app cart: apply a code to the caller\'s own cart', 'api/v1/cart/coupons/{code}' => 'app cart: remove a code from the caller\'s own cart', 'api/v1/cart/acknowledge' => 'app cart: accept changed prices of the caller\'s own cart',
    'api/v1/checkout/quote' => 'app checkout: prices the caller\'s own cart; saves and reserves nothing; a saved address is found only for its owner; throttled critical', 'api/v1/checkout/phone-code' => 'app checkout: sends the SMS code for a large cash order; per-number, per-address and daily limits; throttled otp',
    'api/v1/checkout/phone-confirm' => 'app checkout: checks that code (yes or no only) and returns a signed 30-minute proof for that one phone; attempt-capped; throttled', 'api/v1/checkout/orders' => 'app checkout: places the caller\'s own cart as an order; Idempotency-Key required (one order per owner and key); totals re-checked; throttled critical',
    'api/v1/orders/{id}' => 'app: one order — its signed-in owner, or a guest holding THAT order\'s signed 30-day X-Order-Token (ApiOrderAccess; anyone else is 404, never 403)', 'api/v1/orders/{id}/invoice' => 'app: a 30-minute signed link to the receipt; owner or that order\'s token holder',
    'api/v1/orders/{id}/payments' => 'app: starts an online payment; owner or order token; Idempotency-Key required; nothing the caller says marks anything paid', 'api/v1/orders/{id}/payment' => 'app: the payment status the server recorded; owner or order token', 'api/v1/orders/{id}/payment/verify' => 'app: makes the server ask the gateway (server to server); owner or order token; throttled critical',
    'api/v1/orders/{id}/tracking' => 'app: parcel stages with no address or phone; owner or order token',
    'api/v1/reviews' => 'app catalog: published reviews of a product (first name and last initial only); throttled public-read, ETag',
    'api/v1/services/{sku}/slots' => 'app: the really free times of a bookable service on a day; no customer data; throttled search, never cached', 'api/v1/services/{sku}/hold' => 'app: holds one time in the caller\'s own cart (guest X-Cart-Token or customer); a wrong bearer token is refused; throttled critical; the database allows a time to be held once',
    'api/v1/config' => 'app bootstrap: languages, currency, app versions, shop contact and page links; no customer data; throttled public-read, cached with an ETag',
    'api/v1/webhooks/couriers/{courier}' => 'courier messages about parcels: cannot log in; the adapter authenticates each request as that courier\'s documents describe (a failing request is refused and stored nowhere), events are stored once, throttled',
    'api/v1/webhooks/payments/{provider}' => 'payment gateway notifications: cannot log in; stored raw, never trusted, verified server to server, throttled',
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
    'admin/logout' => 'ends own session', 'admin/account/sessions' => 'own device list', 'admin/account/sessions/revoke-others' => 'own devices only', 'admin/account/activity' => 'own actions only (filtered to the signed-in person)',
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
    $this->withoutMiddleware([EnforceSessionPolicy::class, ThrottleRequests::class]);   // hundreds of requests as one user to check who is let in; throttling has its own tests
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
