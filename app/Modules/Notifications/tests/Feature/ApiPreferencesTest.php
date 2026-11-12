<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

/* Phase 11, slice 6a: what marketing a customer wants. Every change is recorded; nobody can change anyone else's. */

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->amina = User::factory()->verified()->create(['email' => 'amina@example.com']);
    $this->babul = User::factory()->verified()->create(['email' => 'babul@example.com']);
    $this->as = function (User $u) {
        auth()->forgetGuards();

        return ['Authorization' => 'Bearer '.app(IssueApiSession::class)->start($u, 'Pixel', 'android', 'password')->accessToken];
    };
});

it('starts with no marketing and no quiet hours', function () {
    $res = $this->getJson('/api/v1/me/preferences', ($this->as)($this->amina));

    $res->assertOk()->assertJsonPath('data.marketing', ['email' => false, 'sms' => false])->assertJsonPath('data.quiet_hours', ['from' => null, 'to' => null])->assertJsonPath('data.history', []);
    assertMatchesOpenApi($res, 'GET', '/api/v1/me/preferences');
});

it('saves the whole choice, records each change with where it came from, and leaves other people alone', function () {
    $res = $this->putJson('/api/v1/me/preferences', ['email' => true, 'sms' => false, 'quiet_from' => '22:00', 'quiet_to' => '07:00'], ($this->as)($this->amina));

    $res->assertOk()->assertJsonPath('data.marketing', ['email' => true, 'sms' => false])->assertJsonPath('data.quiet_hours', ['from' => '22:00', 'to' => '07:00']);
    assertMatchesOpenApi($res, 'PUT', '/api/v1/me/preferences');
    expect(DB::table('notification_consent_events')->where('user_id', $this->amina->id)->where('channel', 'email')->where('granted', 1)->value('source'))->toBe('account')
        ->and(DB::table('notification_preferences')->where('user_id', $this->babul->id)->count())->toBe(0);

    $this->putJson('/api/v1/me/preferences', ['email' => true, 'sms' => false], ($this->as)($this->amina))->assertOk();
    expect(DB::table('notification_consent_events')->where('user_id', $this->amina->id)->count())->toBe(1); // an unchanged answer is not a new event

    $off = $this->putJson('/api/v1/me/preferences', ['email' => false, 'sms' => false], ($this->as)($this->amina));
    $off->assertOk()->assertJsonPath('data.marketing.email', false)->assertJsonPath('data.quiet_hours.from', null);
    expect(collect($off->json('data.history'))->pluck('granted')->all())->toContain(false, true);
});

it('refuses a half-sent choice, so a missing field can never turn marketing off by accident', function () {
    $h = fn () => ($this->as)($this->amina);

    $this->putJson('/api/v1/me/preferences', ['email' => true], $h())->assertStatus(422)->assertJsonValidationErrors(['sms']);
    $this->putJson('/api/v1/me/preferences', [], $h())->assertStatus(422);
    $this->putJson('/api/v1/me/preferences', ['email' => true, 'sms' => true, 'quiet_from' => '22:00'], $h())->assertStatus(422)->assertJsonValidationErrors(['quiet_to']);
    $this->putJson('/api/v1/me/preferences', ['email' => true, 'sms' => true, 'quiet_from' => '22:00', 'quiet_to' => '22:00'], $h())->assertStatus(422);
    $this->putJson('/api/v1/me/preferences', ['email' => true, 'sms' => true, 'quiet_from' => '25:00', 'quiet_to' => '07:00'], $h())->assertStatus(422);
    expect(DB::table('notification_preferences')->count())->toBe(0);
});

it('reads Bangla digits in the hours, and needs a signed-in customer', function () {
    $this->putJson('/api/v1/me/preferences', ['email' => false, 'sms' => true, 'quiet_from' => '২২:০০', 'quiet_to' => '০৭:০০'], ($this->as)($this->amina))->assertOk()->assertJsonPath('data.quiet_hours.from', '22:00');
    auth()->forgetGuards(); // the test client keeps the guard's last answer between calls
    $this->getJson('/api/v1/me/preferences')->assertStatus(401);
    $this->putJson('/api/v1/me/preferences', ['email' => true, 'sms' => true])->assertStatus(401);
});
