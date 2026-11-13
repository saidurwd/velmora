<?php

declare(strict_types=1);

use App\Modules\Platform\Support\TrustedProxies;

/* Phase 12: found by the load test. The documented setting `TRUSTED_PROXIES=*` used to become a list holding a literal "*", which Laravel does not read as "trust all". */

it('keeps a wildcard as the plain string Laravel understands', function (string $setting) {
    expect(TrustedProxies::parse($setting))->toBe(trim($setting));
})->with(['*', '**', ' * ']);

it('reads a list of addresses and ranges', function () {
    expect(TrustedProxies::parse('10.0.0.1, 10.1.0.0/16 ,'))->toBe(['10.0.0.1', '10.1.0.0/16'])->and(TrustedProxies::parse('192.168.1.5'))->toBe(['192.168.1.5']);
});

it('trusts nobody when the setting is empty', function (?string $setting) {
    expect(TrustedProxies::parse($setting))->toBeNull();
})->with([null, '', '   ', ' , ']);
