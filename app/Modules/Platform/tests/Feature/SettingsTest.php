<?php

declare(strict_types=1);

use App\Modules\Platform\Support\Settings;
use Illuminate\Support\Facades\DB;

it('falls back to config defaults, then caller default', function () {
    $settings = app(Settings::class);

    expect($settings->bool('tax.enabled'))->toBeFalse()   // owner is not VAT-registered yet
        ->and($settings->bool('tax.prices_include_tax'))->toBeTrue()
        ->and($settings->get('nope', 'dflt'))->toBe('dflt');
});

it('stores typed values and invalidates the cache on change', function () {
    $settings = app(Settings::class);
    $settings->get('tax.enabled');            // primes the cache

    $settings->set('tax.enabled', true);
    $settings->set('cod.max_order_minor', 500000);

    expect($settings->bool('tax.enabled'))->toBeTrue()
        ->and($settings->int('cod.max_order_minor'))->toBe(500000);

    $settings->forget('tax.enabled');
    expect($settings->bool('tax.enabled'))->toBeFalse();
});

it('encrypts secrets at rest', function () {
    $settings = app(Settings::class);
    $settings->set('sms.token', 'super-secret-token', secret: true);

    $raw = (string) DB::table('settings')->where('key', 'sms.token')->value('value');

    expect($raw)->not->toContain('super-secret-token')
        ->and($settings->string('sms.token'))->toBe('super-secret-token');
});
