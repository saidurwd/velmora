<?php

declare(strict_types=1);

use App\Modules\Platform\Providers\PlatformServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

it('sends baseline security headers and a report-only CSP', function () {
    $response = $this->get('/healthz');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Content-Security-Policy');

    expect($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'");
});

it('can switch the CSP to enforcing', function () {
    config(['security.csp.enforce' => true]);

    $response = $this->get('/healthz');

    expect($response->headers->get('Content-Security-Policy'))->toContain("script-src 'self'")
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

it('refuses to boot production with debug on, no key or insecure cookies', function () {
    $provider = new PlatformServiceProvider(app());
    app()->detectEnvironment(fn () => 'production');
    config(['app.debug' => true, 'session.secure' => false]);

    expect(fn () => $provider->assertSafeConfiguration())->toThrow(RuntimeException::class, 'APP_DEBUG must be false');

    config(['app.debug' => false, 'session.secure' => true]);
    $provider->assertSafeConfiguration();
    expect(true)->toBeTrue();
});

it('throttles the auth tier at 10 requests a minute per IP', function () {
    Route::get('/_test/auth-tier', fn () => 'ok')->middleware('throttle:auth');
    RateLimiter::clear('auth');

    foreach (range(1, 10) as $i) {
        $this->get('/_test/auth-tier')->assertOk();
    }

    $this->get('/_test/auth-tier')->assertStatus(429);
});

it('applies the OTP tier of 3 sends per 10 minutes per IP', function () {
    Route::get('/_test/otp-tier', fn () => 'ok')->middleware('throttle:otp');

    foreach (range(1, 3) as $i) {
        $this->get('/_test/otp-tier')->assertOk();
    }

    $this->get('/_test/otp-tier')->assertStatus(429);
});

it('returns problem+json with Retry-After when an API route is throttled', function () {
    Route::get('/api/v1/_test/limited', fn () => 'ok')->middleware('throttle:otp');

    foreach (range(1, 3) as $i) {
        $this->getJson('/api/v1/_test/limited')->assertOk();
    }

    $this->getJson('/api/v1/_test/limited')->assertStatus(429)->assertJson(['code' => 'rate.limited'])->assertHeader('Retry-After');
});
