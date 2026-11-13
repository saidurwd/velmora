<?php

declare(strict_types=1);

use App\Modules\Platform\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/* The visitor's address comes from X-Forwarded-For only from a proxy that is trusted, and then the rate limits are per visitor, not per load balancer. */

it('takes the visitor\'s address from X-Forwarded-For when every caller is trusted, and ignores it when nobody is', function () {
    $ip = function (string|array|null $trusted): string {
        TrustProxies::at($trusted ?? []);
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '172.16.0.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.77']);
        (new TrustProxies)->handle($request, fn ($r) => response('ok'));

        return (string) $request->ip();
    };

    expect($ip(TrustedProxies::parse('*')))->toBe('203.0.113.77')
        ->and($ip(TrustedProxies::parse('172.16.0.0/12')))->toBe('203.0.113.77')
        ->and($ip(TrustedProxies::parse('10.0.0.1')))->toBe('172.16.0.9')
        ->and($ip(TrustedProxies::parse('')))->toBe('172.16.0.9');
    TrustProxies::flushState();
});

it('reads the setting through the config file, so a TRUSTED_PROXIES line in .env counts', function () {
    putenv('TRUSTED_PROXIES=*');
    $wildcard = (require base_path('config/trustedproxy.php'))['proxies'];
    putenv('TRUSTED_PROXIES=10.0.0.1,10.2.0.0/16');
    $list = (require base_path('config/trustedproxy.php'))['proxies'];
    putenv('TRUSTED_PROXIES');

    expect($wildcard)->toBe('*')->and($list)->toBe(['10.0.0.1', '10.2.0.0/16']);
});
