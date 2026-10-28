<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Mfa\PendingStaffLogin;
use App\Modules\IdentityAccess\Models\StaffMfaCredential;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Notifications\ResetPasswordNotification;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\FrozenClock;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

function staffLoginStepOne(object $test, User $staff, string $password = GOOD_PASSWORD): TestResponse
{
    $response = $test->post('/admin/login', ['email' => $staff->email, 'password' => $password]);
    carrySession($test, $response);

    return $response;
}

/** Enrols MFA through the real HTTP flow and returns [secret, recovery codes]. */
function enrolStaff(object $test, User $staff): array
{
    staffLoginStepOne($test, $staff)->assertRedirect('/admin/mfa/enrol');
    $page = $test->get('/admin/mfa/enrol')->assertOk();
    carrySession($test, $page);

    $secret = StaffMfaCredential::where('user_id', $staff->id)->firstOrFail()->secret;
    $response = $test->post('/admin/mfa/enrol', ['code' => (new Google2FA)->getCurrentOtp($secret)]);
    carrySession($test, $response);

    return [$secret, session('recovery_codes') ?? []];
}

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = User::factory()->staff()->create(['email' => 'boss@example.com']);
    $this->staff->assignRole('Admin'); // can reach the dashboard (dashboard.view)
});

describe('step 1: password', function () {
    it('signs nobody in on a correct password: it only unlocks the second step', function () {
        staffLoginStepOne($this, $this->staff)->assertRedirect('/admin/mfa/enrol');

        $this->assertGuest('admin');
        $this->get('/admin')->assertRedirect('/admin/login');
    });

    it('gives one generic error for wrong password and unknown address', function () {
        $this->from('/admin/login')->post('/admin/login', ['email' => 'boss@example.com', 'password' => 'nope-nope-nope'])->assertSessionHasErrors('email');
        RateLimiter::clear('x');
        $this->from('/admin/login')->post('/admin/login', ['email' => 'nobody@example.com', 'password' => GOOD_PASSWORD])->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    });

    it('does not let a customer account use the staff login', function () {
        $customer = User::factory()->create(['email' => 'cust@example.com']);

        $this->from('/admin/login')->post('/admin/login', ['email' => 'cust@example.com', 'password' => GOOD_PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    });

    it('does not let a staff account use the customer login', function () {
        $this->post('/en/login', ['identifier' => 'boss@example.com', 'password' => GOOD_PASSWORD]);

        $this->assertGuest('web');
        $this->assertGuest('admin');
    });

    it('keeps the MFA pages unreachable without a correct password first', function () {
        $this->get('/admin/mfa/enrol')->assertRedirect('/admin/login');
        $this->get('/admin/mfa/challenge')->assertRedirect('/admin/login');
        $this->post('/admin/mfa/challenge', ['code' => '123456'])->assertRedirect('/admin/login');
    });

    it('expires the half-finished login after five minutes', function () {
        $clock = new FrozenClock('2026-06-01 10:00:00');
        app()->instance(Clock::class, $clock);
        app()->forgetInstance(PendingStaffLogin::class);

        staffLoginStepOne($this, $this->staff)->assertRedirect('/admin/mfa/enrol');
        $this->get('/admin/mfa/enrol')->assertOk();

        $clock->advanceSeconds(301);
        $this->get('/admin/mfa/enrol')->assertRedirect('/admin/login');
    });
});

describe('first login: forced enrolment', function () {
    it('shows a QR code and a manual key, never the secret anywhere else', function () {
        staffLoginStepOne($this, $this->staff);

        $html = $this->get('/admin/mfa/enrol')->assertOk()->assertSee('<svg', false)->getContent();

        $secret = StaffMfaCredential::where('user_id', $this->staff->id)->firstOrFail()->secret;
        expect($html)->toContain($secret)
            ->and((string) DB::table('staff_mfa_credentials')->value('secret'))->not->toContain($secret); // encrypted at rest
    });

    it('turns MFA on with a valid code, signs in and issues eight single-view recovery codes', function () {
        [, $codes] = enrolStaff($this, $this->staff);

        $this->assertAuthenticatedAs($this->staff, 'admin');
        expect($codes)->toHaveCount(8)
            ->and(StaffMfaCredential::where('user_id', $this->staff->id)->first()->isConfirmed())->toBeTrue()
            ->and(json_encode(DB::table('staff_mfa_credentials')->value('recovery_codes')))->not->toContain($codes[0])
            ->and(DB::table('audit_events')->where('action', 'auth.mfa_enrolled')->count())->toBe(1);
    });

    it('rejects a wrong code during enrolment and stays signed out', function () {
        staffLoginStepOne($this, $this->staff);
        $this->get('/admin/mfa/enrol');

        $this->post('/admin/mfa/enrol', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest('admin');
        expect(StaffMfaCredential::first()->isConfirmed())->toBeFalse();
    });

    it('cannot be enrolled twice (an enrolled factor is not replaced by a new login)', function () {
        enrolStaff($this, $this->staff);
        auth('admin')->logout();

        $this->get('/admin/mfa/enrol')->assertRedirect(); // pending state is gone: back to login
        expect(StaffMfaCredential::count())->toBe(1);
    });
});

describe('later logins: challenge', function () {
    beforeEach(function () {
        [$this->secret, $this->codes] = enrolStaff($this, $this->staff);
        auth('admin')->logout();
        $this->flushSession();
        RateLimiter::clear('x');
    });

    it('signs in with a valid TOTP code', function () {
        staffLoginStepOne($this, $this->staff)->assertRedirect('/admin/mfa/challenge');

        // the code used at enrolment is spent; move to the next 30-second step
        DB::table('staff_mfa_credentials')->update(['last_used_step' => 1]);
        $this->post('/admin/mfa/challenge', ['code' => (new Google2FA)->getCurrentOtp($this->secret)])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($this->staff, 'admin');
        expect(DB::table('audit_events')->where('action', 'auth.mfa_passed')->count())->toBe(1);
    });

    it('refuses to accept the same TOTP code twice (replay)', function () {
        DB::table('staff_mfa_credentials')->update(['last_used_step' => 1]);
        $code = (new Google2FA)->getCurrentOtp($this->secret);

        staffLoginStepOne($this, $this->staff);
        $this->post('/admin/mfa/challenge', ['code' => $code])->assertRedirect('/admin');
        auth('admin')->logout();
        $this->flushSession();

        staffLoginStepOne($this, $this->staff);
        $this->from('/admin/mfa/challenge')->post('/admin/mfa/challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest('admin');
    });

    it('rejects a wrong code and records the failure', function () {
        staffLoginStepOne($this, $this->staff);

        $this->from('/admin/mfa/challenge')->post('/admin/mfa/challenge', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest('admin');
        expect(DB::table('audit_events')->where('action', 'auth.mfa_failed')->count())->toBe(1);
    });

    it('ends the attempt after five wrong codes: the password step must be repeated', function () {
        staffLoginStepOne($this, $this->staff);

        foreach (range(1, 4) as $i) {
            $this->post('/admin/mfa/challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->post('/admin/mfa/challenge', ['code' => '000000'])->assertRedirect('/admin/login');

        $this->get('/admin/mfa/challenge')->assertRedirect('/admin/login'); // pending state was cleared
        $this->assertGuest('admin');
    });

    it('accepts each recovery code exactly once', function () {
        staffLoginStepOne($this, $this->staff);
        $this->post('/admin/mfa/challenge', ['code' => strtoupper($this->codes[0])])->assertRedirect('/admin'); // case/dash tolerant

        $this->assertAuthenticatedAs($this->staff, 'admin');
        expect(DB::table('audit_events')->where('action', 'auth.recovery_code_used')->count())->toBe(1);

        auth('admin')->logout();
        $this->flushSession();
        RateLimiter::clear('x');

        staffLoginStepOne($this, $this->staff);
        $this->from('/admin/mfa/challenge')->post('/admin/mfa/challenge', ['code' => $this->codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest('admin');

        $this->post('/admin/mfa/challenge', ['code' => $this->codes[1]])->assertRedirect('/admin');
    });
});

describe('admin area access', function () {
    it('keeps customers out of every admin route', function () {
        $customer = User::factory()->create();

        $this->actingAs($customer, 'web')->get('/admin')->assertRedirect('/admin/login');
        $this->actingAs($customer, 'web')->get('/admin/account/sessions')->assertRedirect('/admin/login');
    });

    it('keeps staff out of the customer account area', function () {
        $this->actingAs($this->staff, 'admin')->get('/en/account')->assertRedirect('/en/login');
    });

    it('times staff out after 30 idle minutes', function () {
        $clock = new FrozenClock('2026-06-01 10:00:00');
        app()->instance(Clock::class, $clock);

        enrolStaff($this, $this->staff);
        $this->get('/admin')->assertOk();

        $clock->advanceSeconds(31 * 60);
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->assertGuest('admin');
    });

    it('keeps an active staff session alive', function () {
        $clock = new FrozenClock('2026-06-01 10:00:00');
        app()->instance(Clock::class, $clock);

        enrolStaff($this, $this->staff);

        foreach (range(1, 4) as $i) {
            $clock->advanceSeconds(20 * 60);
            $this->get('/admin')->assertOk();
        }
    });

    it('lists devices and signs out the other ones', function () {
        enrolStaff($this, $this->staff);
        DB::table('user_sessions')->insert(['user_id' => $this->staff->id, 'session_id_hash' => str_repeat('b', 64), 'session_id_encrypted' => Crypt::encryptString('other-device'), 'guard' => 'admin', 'ip' => '203.0.113.7', 'user_agent' => 'Other Browser', 'last_seen_at' => now(), 'created_at' => now()]);

        $this->get('/admin/account/sessions')->assertOk()->assertSee('Other Browser')->assertSee('this device');

        $this->post('/admin/account/sessions/revoke-others')->assertRedirect();

        expect(DB::table('user_sessions')->where('guard', 'admin')->count())->toBe(1);
        $this->get('/admin')->assertOk(); // our own session survived
    });

    it('signs out and ends the device record', function () {
        enrolStaff($this, $this->staff);

        $this->post('/admin/logout')->assertRedirect('/admin/login');

        $this->assertGuest('admin');
        expect(DB::table('user_sessions')->where('guard', 'admin')->count())->toBe(0);
    });
});

describe('staff password reset', function () {
    it('mails a reset link only to real staff, with an admin URL (not a customer one)', function () {
        $this->from('/admin/forgot-password')->post('/admin/forgot-password', ['email' => 'boss@example.com'])->assertSessionHas('status');
        $this->post('/admin/forgot-password', ['email' => 'nobody@example.com']);

        Notification::assertSentTo($this->staff, ResetPasswordNotification::class, function ($n) {
            $url = $n->toMail($this->staff)->actionUrl;

            return str_contains($url, '/admin/reset-password/') && ! str_contains($url, '/en/');
        });
        Notification::assertCount(1);
    });

    it('does not use a customer reset token for a staff account', function () {
        $customer = User::factory()->create(['email' => 'cust@example.com']);
        $token = Password::broker('users')->createToken($customer);

        $this->from('/admin/reset-password/x')->post('/admin/reset-password', ['token' => $token, 'email' => 'cust@example.com', 'password' => 'A-brand-new-Passphrase-77', 'password_confirmation' => 'A-brand-new-Passphrase-77'])
            ->assertSessionHasErrors('email');
    });
});
