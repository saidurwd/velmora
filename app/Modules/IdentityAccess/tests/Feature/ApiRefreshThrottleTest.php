<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;

/* Phase 11, slice 8 follow-up (ADR P11-9): many phones share one carrier address, so refreshing tokens has a higher per-address limit than signing in. */

beforeEach(function () {
    RateLimiter::clear('refresh|203.0.113.5');
    RateLimiter::clear('refresh|203.0.113.6');
    $this->refresh = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/refresh', ['refresh_token' => str_repeat('a', 64)]);
    // sign-in uses a new name each time: its per-account lockout is a different limit from the per-address one under test
    $this->login = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/login', ['identifier' => 'nobody'.bin2hex(random_bytes(4)).'@example.com', 'password' => 'wrong-password-1', 'device_name' => 'P']);
});

it('lets sixty refreshes a minute from one address through, where sign-in allows ten', function () {
    for ($i = 0; $i < 12; $i++) {
        ($this->refresh)('203.0.113.5')->assertStatus(401)->assertJsonPath('code', 'auth.refresh_invalid');
    }
    for ($i = 0; $i < 10; $i++) {
        ($this->login)('203.0.113.6')->assertStatus(401);
    }
    ($this->login)('203.0.113.6')->assertStatus(429);
});

it('still stops a flood: the sixty-first refresh in a minute is refused with Retry-After, and another address is not affected', function () {
    for ($i = 0; $i < 60; $i++) {
        ($this->refresh)('203.0.113.5');
    }

    ($this->refresh)('203.0.113.5')->assertStatus(429)->assertJsonPath('code', 'rate.limited')->assertHeader('Retry-After');
    ($this->refresh)('203.0.113.6')->assertStatus(401);
});

it('keeps refresh limited by address, not shared with the sign-in count', function () {
    for ($i = 0; $i < 10; $i++) {
        ($this->login)('203.0.113.5');
    }

    ($this->login)('203.0.113.5')->assertStatus(429);
    ($this->refresh)('203.0.113.5')->assertStatus(401);
});
