<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Actions\ChangePassword;
use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Jobs\PurgeExpiredRefreshTokens;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Privacy\AccountPersonalData;
use App\Modules\Notifications\Privacy\NotificationPersonalData;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/* Phase 11, slice 2: the rules around app sign-ins that are not a single endpoint — limits, clean-up, privacy, and the website's part. */

const SESSION_PASSWORD = 'Tr0ub4dor-and-3-horses';

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    Notification::fake();
    $this->customer = User::factory()->verified()->create(['email' => 'amina@example.com', 'phone' => '+8801712345678', 'password' => SESSION_PASSWORD]);
    $this->signIn = fn (string $device = 'Pixel') => app(IssueApiSession::class)->start($this->customer, $device, 'android', 'password');
    $this->h = function ($pair) {
        auth()->forgetGuards();

        return ['Authorization' => 'Bearer '.$pair->accessToken];
    };
});

it('keeps at most the allowed number of sign-ins and ends the oldest when a new one starts', function () {
    config(['api.tokens.max_sessions_per_user' => 3]);
    $first = ($this->signIn)('one');
    ($this->signIn)('two');
    ($this->signIn)('three');
    $fourth = ($this->signIn)('four');

    expect(DB::table('refresh_tokens')->where('user_id', $this->customer->id)->where('status', 'active')->pluck('device_name')->sort()->values()->all())->toBe(['four', 'three', 'two']);
    $this->getJson('/api/v1/me', ($this->h)($first))->assertStatus(401);
    $this->getJson('/api/v1/me', ($this->h)($fourth))->assertOk();
});

it('deletes refresh-token rows a week after they expired, and only those', function () {
    $old = ($this->signIn)('old');
    $recent = ($this->signIn)('recent');
    DB::table('refresh_tokens')->where('device_name', 'old')->update(['expires_at' => now('UTC')->subDays(8)->format('Y-m-d H:i:s.u')]);
    DB::table('refresh_tokens')->where('device_name', 'recent')->update(['expires_at' => now('UTC')->subDays(2)->format('Y-m-d H:i:s.u')]);

    (new PurgeExpiredRefreshTokens)->handle();

    expect(DB::table('refresh_tokens')->pluck('device_name')->all())->toBe(['recent'])->and($old->sessionId)->not->toBe($recent->sessionId);
});

it('ends every app sign-in when the password is changed on the website', function () {
    $pair = ($this->signIn)();
    // the website calls this same Action (with no app sign-in to keep), so every app sign-in ends
    app(ChangePassword::class)->handle($this->customer->fresh(), 'An0ther-Str0ng-Pass-77', 'web-session-id');

    $this->getJson('/api/v1/me', ($this->h)($pair))->assertStatus(401);
    expect(DB::table('refresh_tokens')->where('user_id', $this->customer->id)->where('status', 'active')->count())->toBe(0);
});

it('confirms it is you with a code sent to the verified phone (for people with no password to type)', function () {
    $pair = ($this->signIn)();
    $sms = app(FakeSmsGateway::class);

    $this->postJson('/api/v1/auth/reauth/code', [], ($this->h)($pair))->assertStatus(202);
    $this->postJson('/api/v1/auth/reauth', ['code' => '000000'], ($this->h)($pair))->assertStatus(401)->assertJsonPath('code', 'auth.otp_invalid');
    $this->postJson('/api/v1/auth/reauth', ['code' => (string) $sms->lastCode()], ($this->h)($pair))->assertNoContent();
    $this->postJson('/api/v1/auth/reauth', ['code' => '123456', 'password' => SESSION_PASSWORD], ($this->h)($pair))->assertStatus(422);
    $this->postJson('/api/v1/auth/reauth', [], ($this->h)($pair))->assertStatus(422);
});

it('puts app sign-ins and devices in the data copy and deletes them on erasure', function () {
    $pair = ($this->signIn)('Amina’s Pixel');
    $this->postJson('/api/v1/devices', ['push_token' => str_repeat('fcm-token-', 12), 'platform' => 'android', 'app_version' => '1.0.0'], ($this->h)($pair))->assertCreated();

    $copy = app(NotificationPersonalData::class)->export($this->customer->id);
    expect($copy['devices'])->toHaveCount(1)->and($copy['devices'][0]['platform'])->toBe('android')->and(json_encode($copy['devices']))->not->toContain('fcm-token');

    app(NotificationPersonalData::class)->erase($this->customer->id);
    app(AccountPersonalData::class)->erase($this->customer->id);

    expect(DB::table('devices')->count())->toBe(0)->and(DB::table('refresh_tokens')->count())->toBe(0)->and(DB::table('personal_access_tokens')->count())->toBe(0);
    $this->getJson('/api/v1/me', ($this->h)($pair))->assertStatus(401);
});

it('writes who signed in, how, and when it was ended — never a token', function () {
    $pair = ($this->signIn)();
    $this->postJson('/api/v1/auth/logout-all', [], ($this->h)($pair))->assertNoContent();

    $events = DB::table('audit_events')->whereIn('action', ['auth.api_login', 'auth.api_logout_all'])->orderBy('id')->get();
    expect($events->pluck('action')->all())->toBe(['auth.api_login', 'auth.api_logout_all'])
        ->and(json_encode($events))->not->toContain($pair->refreshToken)->not->toContain(explode('|', $pair->accessToken)[1]);
});

it('does not let one person use another person’s refresh token to end or list anything of theirs', function () {
    $mine = ($this->signIn)();
    $other = User::factory()->verified()->create(['email' => 'babul@example.com', 'password' => SESSION_PASSWORD]);
    $theirs = app(IssueApiSession::class)->start($other, 'Babul’s phone', 'ios', 'password');

    $this->getJson('/api/v1/me/tokens', ($this->h)($mine))->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/auth/logout-all', [], ($this->h)($mine))->assertNoContent();
    $this->getJson('/api/v1/me', ($this->h)($theirs))->assertOk();
});
