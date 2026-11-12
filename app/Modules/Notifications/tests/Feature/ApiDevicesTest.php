<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

/* Phase 11, slice 2: push-token registration. A device belongs to one person and one sign-in; signing out takes it away. */

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->amina = User::factory()->verified()->create(['email' => 'amina@example.com', 'password' => 'Tr0ub4dor-and-3-horses']);
    $this->babul = User::factory()->verified()->create(['email' => 'babul@example.com', 'password' => 'Tr0ub4dor-and-3-horses']);
    $this->signIn = fn (string $email) => test()->postJson('/api/v1/auth/login', ['identifier' => $email, 'password' => 'Tr0ub4dor-and-3-horses', 'device_name' => 'Phone'])->json('data');
    $this->h = function (array $pair) {
        auth()->forgetGuards(); // the test client keeps the guard's last answer between calls; a real server starts every request clean

        return ['Authorization' => 'Bearer '.$pair['access_token']];
    };
    $this->push = str_repeat('fcm-token-', 12);
});

it('registers a push token for this sign-in, stores it encrypted and hashed, and registering again updates the same device', function () {
    $pair = ($this->signIn)('amina@example.com');

    $res = $this->postJson('/api/v1/devices', ['push_token' => $this->push, 'platform' => 'android', 'app_version' => '1.2.0'], ($this->h)($pair));
    $res->assertCreated()->assertJsonStructure(['data' => ['id', 'platform', 'app_version', 'locale']]);
    assertMatchesOpenApi($res, 'POST', '/api/v1/devices');

    $row = DB::table('devices')->first();
    expect($row->push_token_hash)->toBe(hash('sha256', $this->push))->and($row->push_token_encrypted)->not->toContain($this->push);

    $this->postJson('/api/v1/devices', ['push_token' => $this->push, 'platform' => 'android', 'app_version' => '1.3.0'], ($this->h)($pair))->assertOk();
    expect(DB::table('devices')->count())->toBe(1)->and(DB::table('devices')->value('app_version'))->toBe('1.3.0');
});

it('moves a device to the person who signed in on it last, so pushes never reach the previous owner', function () {
    $a = ($this->signIn)('amina@example.com');
    $this->postJson('/api/v1/devices', ['push_token' => $this->push, 'platform' => 'android'], ($this->h)($a))->assertCreated();

    $b = ($this->signIn)('babul@example.com');
    $this->postJson('/api/v1/devices', ['push_token' => $this->push, 'platform' => 'android'], ($this->h)($b))->assertSuccessful();

    expect(DB::table('devices')->count())->toBe(1)->and((int) DB::table('devices')->value('user_id'))->toBe($this->babul->id);
});

it('removes the device when its sign-in ends, and on request; never somebody else’s', function () {
    $a = ($this->signIn)('amina@example.com');
    $b = ($this->signIn)('babul@example.com');
    $mine = $this->postJson('/api/v1/devices', ['push_token' => $this->push, 'platform' => 'android'], ($this->h)($a))->json('data.id');
    $theirs = $this->postJson('/api/v1/devices', ['push_token' => str_repeat('other-', 20), 'platform' => 'ios'], ($this->h)($b))->json('data.id');

    $this->deleteJson('/api/v1/devices/'.$theirs, [], ($this->h)($a))->assertNotFound();
    expect(DB::table('devices')->count())->toBe(2);

    $this->postJson('/api/v1/auth/logout', [], ($this->h)($a))->assertNoContent();
    expect(DB::table('devices')->where('public_id', $mine)->exists())->toBeFalse();

    $this->deleteJson('/api/v1/devices/'.$theirs, [], ($this->h)($b))->assertNoContent();
    expect(DB::table('devices')->count())->toBe(0);
});

it('validates the registration and needs a signed-in customer', function () {
    $pair = ($this->signIn)('amina@example.com');

    $this->postJson('/api/v1/devices', ['push_token' => 'short', 'platform' => 'toaster'], ($this->h)($pair))->assertStatus(422)->assertJsonValidationErrors(['push_token', 'platform']);
    auth()->forgetGuards();
    $this->postJson('/api/v1/devices', ['push_token' => $this->push, 'platform' => 'android'])->assertStatus(401);
});

it('ends all of a person’s devices when every sign-in ends', function () {
    $a = ($this->signIn)('amina@example.com');
    $this->postJson('/api/v1/devices', ['push_token' => $this->push, 'platform' => 'android'], ($this->h)($a))->assertCreated();

    $this->postJson('/api/v1/auth/logout-all', [], ($this->h)($a))->assertNoContent();

    expect(DB::table('devices')->count())->toBe(0);
});
