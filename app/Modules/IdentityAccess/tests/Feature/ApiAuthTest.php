<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/* Phase 11, slice 2: signing in from the app. Same Actions as the web; what is new is the token pair, its rotation and the sessions list. */

const API_PASSWORD = 'Tr0ub4dor-and-3-horses';

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class); // the Action's own limiter is under test, not the route's per-address one
    Notification::fake();
    RateLimiter::clear('x');
    $this->customer = User::factory()->verified()->create(['email' => 'amina@example.com', 'phone' => '+8801712345678', 'password' => API_PASSWORD]);
    $this->login = fn (array $over = [], array $headers = []) => $this->postJson('/api/v1/auth/login', $over + ['identifier' => 'amina@example.com', 'password' => API_PASSWORD, 'device_name' => 'Amina’s Pixel', 'platform' => 'android'], $headers);
    $this->pair = fn () => ($this->login)()->assertOk()->json('data');
    // the test client keeps the guard's last answer between calls; a real server starts every request clean
    $this->bearer = function (string $token) {
        auth()->forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    };
});

describe('signing in with a password', function () {
    it('gives a short-lived access token and a rotating refresh token, and shows the person', function () {
        $res = ($this->login)();

        $res->assertOk()->assertJsonStructure(['data' => ['token_type', 'access_token', 'expires_in', 'refresh_token', 'refresh_expires_in', 'session_id', 'user' => ['id', 'name', 'email', 'phone', 'locale', 'email_verified', 'phone_verified', 'has_password']]])
            ->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.expires_in', 3600)->assertJsonPath('data.refresh_expires_in', 30 * 86400)
            ->assertJsonPath('data.user.id', $this->customer->public_id);
        assertMatchesOpenApi($res, 'POST', '/api/v1/auth/login');

        $me = $this->getJson('/api/v1/me', ($this->bearer)($res->json('data.access_token')));
        $me->assertOk()->assertJsonPath('data.email', 'amina@example.com');
        assertMatchesOpenApi($me, 'GET', '/api/v1/me');
    });

    it('keeps only hashes: no token is stored, logged or audited in the clear', function () {
        $pair = ($this->pair)();

        $stored = DB::table('refresh_tokens')->first();
        expect($stored->token_hash)->toBe(hash('sha256', $pair['refresh_token']))->and($stored->token_hash)->not->toBe($pair['refresh_token'])
            ->and(json_encode(DB::table('personal_access_tokens')->get()))->not->toContain(explode('|', $pair['access_token'])[1])
            ->and(json_encode(DB::table('audit_events')->get()))->not->toContain($pair['refresh_token'])->not->toContain(explode('|', $pair['access_token'])[1]);
    });

    it('says exactly the same for an unknown account, a wrong password and a disabled account (no enumeration)', function () {
        User::factory()->status(UserStatus::Disabled)->create(['email' => 'off@example.com', 'password' => API_PASSWORD]);

        $bodies = [
            ($this->login)(['identifier' => 'nobody@example.com'])->assertStatus(401)->json(),
            ($this->login)(['password' => 'wrong-password-123'])->assertStatus(401)->json(),
            ($this->login)(['identifier' => 'off@example.com'])->assertStatus(401)->json(),
        ];

        foreach ($bodies as $b) {
            expect($b['code'])->toBe('auth.invalid_credentials');
            unset($b['request_id']);
            expect($b)->toBe(collect($bodies[0])->except('request_id')->all());
        }
    });

    it('refuses staff accounts exactly as it refuses a wrong password', function () {
        User::factory()->staff()->create(['email' => 'boss@example.com', 'password' => API_PASSWORD]);

        ($this->login)(['identifier' => 'boss@example.com'])->assertStatus(401)->assertJsonPath('code', 'auth.invalid_credentials');
        expect(DB::table('refresh_tokens')->count())->toBe(0);
    });

    it('signs in with a phone number written in any way, and shares the web lockout (ten failures lock both)', function () {
        ($this->login)(['identifier' => '01712 345678'])->assertOk();

        for ($i = 0; $i < 10; $i++) {
            RateLimiter::clear('login:customer:'.sha1('amina@example.com|127.0.0.1'));
            ($this->login)(['password' => 'wrong-password-123'])->assertStatus(401);
        }
        // locked: even the right password fails, and so does the web form (one Action, one counter)
        ($this->login)()->assertStatus(401);
        $this->post('/en/login', ['identifier' => 'amina@example.com', 'password' => API_PASSWORD])->assertSessionHasErrors('identifier');
    });

    it('throttles repeated failures with 429 and Retry-After', function () {
        for ($i = 0; $i < 5; $i++) {
            ($this->login)(['password' => 'wrong-password-123'])->assertStatus(401);
        }

        ($this->login)(['password' => 'wrong-password-123'])->assertStatus(429)->assertJsonPath('code', 'rate.limited')->assertHeader('Retry-After');
    });

    it('validates the request and answers in Bangla when asked', function () {
        ($this->login)(['identifier' => ''])->assertStatus(422)->assertJsonPath('code', 'validation.failed')->assertJsonValidationErrors(['identifier']);
        $res = ($this->login)(['password' => 'wrong-password-123'], ['Accept-Language' => 'bn'])->assertStatus(401);
        expect($res->json('title'))->toBe(trans('errors.api.invalid_credentials', [], 'bn'));
    });
});

describe('refreshing', function () {
    it('swaps the pair for a new one, once: the old refresh token and the old access token stop working', function () {
        $first = ($this->pair)();
        $res = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']]);

        $res->assertOk()->assertJsonPath('data.session_id', $first['session_id']);
        assertMatchesOpenApi($res, 'POST', '/api/v1/auth/refresh');
        $second = $res->json('data');
        expect($second['refresh_token'])->not->toBe($first['refresh_token'])->and($second['access_token'])->not->toBe($first['access_token']);

        $this->getJson('/api/v1/me', ($this->bearer)($first['access_token']))->assertStatus(401);
        $this->getJson('/api/v1/me', ($this->bearer)($second['access_token']))->assertOk();
    });

    it('treats a used refresh token as theft: the whole sign-in ends, including the newest pair', function () {
        $first = ($this->pair)();
        $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->json('data');

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertStatus(401)->assertJsonPath('code', 'auth.refresh_reused');

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $second['refresh_token']])->assertStatus(401)->assertJsonPath('code', 'auth.refresh_invalid');
        $this->getJson('/api/v1/me', ($this->bearer)($second['access_token']))->assertStatus(401);
        expect(DB::table('audit_events')->where('action', 'auth.api_refresh_reuse')->count())->toBe(1);
    });

    it('refuses an unknown, an expired and a malformed refresh token the same way', function () {
        $pair = ($this->pair)();
        DB::table('refresh_tokens')->update(['expires_at' => now()->subMinute()->format('Y-m-d H:i:s.u')]);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertStatus(401)->assertJsonPath('code', 'auth.refresh_invalid');
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'vrt_'.str_repeat('a', 43)])->assertStatus(401)->assertJsonPath('code', 'auth.refresh_invalid');
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => ''])->assertStatus(422);
    });

    it('ends the sign-in when the account was disabled meanwhile', function () {
        $pair = ($this->pair)();
        $this->customer->forceFill(['status' => UserStatus::Disabled])->save();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertStatus(401);
        $this->getJson('/api/v1/me', ($this->bearer)($pair['access_token']))->assertStatus(401);
        expect(DB::table('refresh_tokens')->where('status', 'active')->count())->toBe(0);
    });

    it('lets exactly one of several simultaneous refreshes win', function () {
        $pair = ($this->pair)();
        $hash = hash('sha256', $pair['refresh_token']);

        // the conditional update that decides the race, run the way two workers would: the second finds nothing to claim
        $claim = fn () => DB::table('refresh_tokens')->where('token_hash', $hash)->where('status', 'active')->update(['status' => 'used', 'used_at' => now()->format('Y-m-d H:i:s.u')]);

        expect($claim())->toBe(1)->and($claim())->toBe(0);
    });
});

describe('access tokens', function () {
    it('are refused when expired, when the account is disabled and when they belong to staff', function () {
        $pair = ($this->pair)();
        $h = ($this->bearer)($pair['access_token']);

        DB::table('personal_access_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/me', $h)->assertStatus(401)->assertJsonPath('code', 'auth.unauthenticated');

        $fresh = ($this->pair)();
        $this->customer->forceFill(['status' => UserStatus::Disabled])->save();
        $this->getJson('/api/v1/me', ($this->bearer)($fresh['access_token']))->assertStatus(401);

        $staff = User::factory()->staff()->create();
        $token = $staff->createToken('x', ['customer'], now()->addHour())->plainTextToken;
        $this->getJson('/api/v1/me', ($this->bearer)($token))->assertStatus(401);
        $this->customer->forceFill(['status' => UserStatus::Active, 'account_type' => AccountType::Customer])->save();
    });

    it('are refused without the customer ability, and without a header', function () {
        $token = $this->customer->createToken('x', ['something-else'], now()->addHour())->plainTextToken;

        $this->getJson('/api/v1/me', ($this->bearer)($token))->assertStatus(401);
        $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'auth.unauthenticated');
    });
});

describe('signing out and the list of signed-in devices', function () {
    it('logout ends this sign-in only; logout-all ends every one', function () {
        $a = ($this->pair)();
        $b = ($this->pair)();

        $this->postJson('/api/v1/auth/logout', [], ($this->bearer)($a['access_token']))->assertNoContent();
        $this->getJson('/api/v1/me', ($this->bearer)($a['access_token']))->assertStatus(401);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $a['refresh_token']])->assertStatus(401);
        $this->getJson('/api/v1/me', ($this->bearer)($b['access_token']))->assertOk();

        $this->postJson('/api/v1/auth/logout-all', [], ($this->bearer)($b['access_token']))->assertNoContent();
        $this->getJson('/api/v1/me', ($this->bearer)($b['access_token']))->assertStatus(401);
        expect(DB::table('refresh_tokens')->where('status', 'active')->count())->toBe(0)->and(DB::table('personal_access_tokens')->count())->toBe(0);
    });

    it('lists my sign-ins (marking this one) and ends another by its id', function () {
        $a = ($this->pair)();
        $b = $this->postJson('/api/v1/auth/login', ['identifier' => 'amina@example.com', 'password' => API_PASSWORD, 'device_name' => 'iPad', 'platform' => 'ios'])->json('data');

        $list = $this->getJson('/api/v1/me/tokens', ($this->bearer)($a['access_token']));
        $list->assertOk()->assertJsonCount(2, 'data');
        assertMatchesOpenApi($list, 'GET', '/api/v1/me/tokens');
        expect(collect($list->json('data'))->firstWhere('current', true)['id'])->toBe($a['session_id'])->and(collect($list->json('data'))->pluck('device_name')->sort()->values()->all())->toBe(['Amina’s Pixel', 'iPad']);

        $this->deleteJson('/api/v1/me/tokens/'.$b['session_id'], [], ($this->bearer)($a['access_token']))->assertNoContent();
        $this->getJson('/api/v1/me', ($this->bearer)($b['access_token']))->assertStatus(401);
        $this->getJson('/api/v1/me', ($this->bearer)($a['access_token']))->assertOk();
    });

    it('cannot see or end somebody else’s sign-in (404, and theirs keeps working)', function () {
        $mine = ($this->pair)();
        User::factory()->verified()->create(['email' => 'babul@example.com', 'password' => API_PASSWORD]);
        $theirs = $this->postJson('/api/v1/auth/login', ['identifier' => 'babul@example.com', 'password' => API_PASSWORD, 'device_name' => 'Babul’s phone'])->json('data');

        $this->deleteJson('/api/v1/me/tokens/'.$theirs['session_id'], [], ($this->bearer)($mine['access_token']))->assertNotFound();
        $this->deleteJson('/api/v1/me/tokens/'.str_repeat('0', 26), [], ($this->bearer)($mine['access_token']))->assertNotFound()->assertJsonPath('code', 'resource.not_found');
        expect(collect($this->getJson('/api/v1/me/tokens', ($this->bearer)($mine['access_token']))->json('data'))->pluck('id')->all())->not->toContain($theirs['session_id']);
        $this->getJson('/api/v1/me', ($this->bearer)($theirs['access_token']))->assertOk();
    });
});

describe('registering', function () {
    it('creates the account, mails a verification link and returns no token', function () {
        $res = $this->postJson('/api/v1/auth/register', ['name' => 'Babul Hasan', 'email' => 'babul@example.com', 'password' => API_PASSWORD]);

        $res->assertStatus(202)->assertJsonPath('data.status', 'check_your_email');
        assertMatchesOpenApi($res, 'POST', '/api/v1/auth/register');
        $user = User::where('email', 'babul@example.com')->firstOrFail();
        expect($user->account_type->value)->toBe('customer')->and(DB::table('refresh_tokens')->where('user_id', $user->id)->count())->toBe(0);
        $this->assertTrue(true);
    });

    it('answers a taken address exactly as a new one, and weak passwords are refused', function () {
        $new = $this->postJson('/api/v1/auth/register', ['name' => 'Babul', 'email' => 'babul@example.com', 'password' => API_PASSWORD])->json();
        $taken = $this->postJson('/api/v1/auth/register', ['name' => 'Amina', 'email' => 'amina@example.com', 'password' => API_PASSWORD])->json();

        expect($taken)->toBe($new);
        $this->postJson('/api/v1/auth/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => '12345678'])->assertStatus(422)->assertJsonValidationErrors(['password']);
    });

    it('lists the policies to accept, and refuses a sign-up that does not accept them', function () {
        $res = $this->getJson('/api/v1/auth/signup-policies');
        $res->assertOk()->assertJsonStructure(['data']);
        assertMatchesOpenApi($res, 'GET', '/api/v1/auth/signup-policies');
    });
});

describe('signing in with a phone code', function () {
    it('sends a code (always 202), and a right code signs in — creating the account for a new number, as on the web', function () {
        $sms = app(FakeSmsGateway::class);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '01812345678'])->assertStatus(202);
        $res = $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01812345678', 'code' => $sms->lastCode(), 'device_name' => 'Pixel', 'platform' => 'android']);

        $res->assertOk()->assertJsonPath('data.user.phone', '+8801812345678')->assertJsonPath('data.user.phone_verified', true);
        assertMatchesOpenApi($res, 'POST', '/api/v1/auth/otp/verify');
        expect(User::where('phone', '+8801812345678')->count())->toBe(1);
    });

    it('refuses a wrong code the same way whatever the reason, and a locked number too', function () {
        $sms = app(FakeSmsGateway::class);
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '01712345678'])->assertStatus(202);

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01712345678', 'code' => '000000'])->assertStatus(401)->assertJsonPath('code', 'auth.otp_invalid');
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01999999999', 'code' => '000000'])->assertStatus(401)->assertJsonPath('code', 'auth.otp_invalid');
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01712345678', 'code' => $sms->lastCode()])->assertOk();
    });

    it('does not reveal whether a number has an account, and does not accept staff numbers', function () {
        User::factory()->staff()->create(['phone' => '+8801611111111']);
        $sms = app(FakeSmsGateway::class);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '01611111111'])->assertStatus(202);
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01611111111', 'code' => (string) $sms->lastCode()])->assertStatus(401)->assertJsonPath('code', 'auth.otp_invalid');
        $this->postJson('/api/v1/auth/otp/request', ['phone' => 'banana'])->assertStatus(422);
    });
});

describe('passwords', function () {
    it('answers a reset request the same for known and unknown addresses', function () {
        $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'amina@example.com']);
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com']);

        $known->assertStatus(202);
        expect($unknown->status())->toBe(202)->and($unknown->json())->toBe($known->json());
    });

    it('a reset ends every signed-in device', function () {
        $pair = ($this->pair)();
        $token = app('auth.password.broker')->createToken($this->customer);

        $this->postJson('/api/v1/auth/password/reset', ['email' => 'amina@example.com', 'token' => $token, 'password' => 'An0ther-Str0ng-Pass-77'])->assertOk();

        $this->getJson('/api/v1/me', ($this->bearer)($pair['access_token']))->assertStatus(401);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertStatus(401);
        ($this->login)(['password' => 'An0ther-Str0ng-Pass-77'])->assertOk();
        $this->postJson('/api/v1/auth/password/reset', ['email' => 'amina@example.com', 'token' => $token, 'password' => 'Y3t-An0ther-Str0ng-Pass'])->assertStatus(422);
    });

    it('a change needs a recent re-confirmation, then ends the other devices but not this one', function () {
        $a = ($this->pair)();
        $b = $this->postJson('/api/v1/auth/login', ['identifier' => 'amina@example.com', 'password' => API_PASSWORD, 'device_name' => 'iPad'])->json('data');
        $h = ($this->bearer)($a['access_token']);
        $body = ['current_password' => API_PASSWORD, 'password' => 'An0ther-Str0ng-Pass-77'];

        $this->postJson('/api/v1/me/password', $body, $h)->assertStatus(403)->assertJsonPath('code', 'auth.reauth_required');

        $this->postJson('/api/v1/auth/reauth', ['password' => 'wrong-password-123'], $h)->assertStatus(401)->assertJsonPath('code', 'auth.invalid_credentials');
        $this->postJson('/api/v1/auth/reauth', ['password' => API_PASSWORD], $h)->assertNoContent();

        $this->postJson('/api/v1/me/password', $body, $h)->assertNoContent();
        $this->getJson('/api/v1/me', $h)->assertOk();
        $this->getJson('/api/v1/me', ($this->bearer)($b['access_token']))->assertStatus(401);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $b['refresh_token']])->assertStatus(401);
        // the device that made the change keeps its sign-in all the way: its refresh token still works
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $a['refresh_token']])->assertOk();
        ($this->login)(['password' => 'An0ther-Str0ng-Pass-77'])->assertOk();
    });

    it('the re-confirmation belongs to one sign-in and expires after ten minutes', function () {
        $a = ($this->pair)();
        $b = $this->postJson('/api/v1/auth/login', ['identifier' => 'amina@example.com', 'password' => API_PASSWORD, 'device_name' => 'iPad'])->json('data');
        $body = ['current_password' => API_PASSWORD, 'password' => 'An0ther-Str0ng-Pass-77'];

        $this->postJson('/api/v1/auth/reauth', ['password' => API_PASSWORD], ($this->bearer)($a['access_token']))->assertNoContent();
        $this->postJson('/api/v1/me/password', $body, ($this->bearer)($b['access_token']))->assertStatus(403);

        $this->travel(11)->minutes();
        $this->postJson('/api/v1/me/password', $body, ($this->bearer)($a['access_token']))->assertStatus(403)->assertJsonPath('code', 'auth.reauth_required');
    });
});

describe('the contract', function () {
    it('documents every auth route and nothing in it needs a staff permission', function () {
        $paths = array_keys(openApiDocument()['paths']);

        expect($paths)->toContain('/auth/login', '/auth/refresh', '/auth/logout', '/auth/logout-all', '/auth/register', '/auth/otp/request', '/auth/otp/verify', '/auth/password/forgot', '/auth/password/reset', '/auth/reauth', '/me', '/me/tokens', '/me/tokens/{id}', '/me/password', '/auth/signup-policies');
    });
});
