<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Notifications\AccountAlreadyExistsNotification;
use App\Modules\IdentityAccess\Notifications\ConfirmNewEmailNotification;
use App\Modules\IdentityAccess\Notifications\VerifyEmailNotification;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/* Phase 11, slice 6a: changing how the shop reaches a person. Sensitive, so each change needs this sign-in to have confirmed it is them a few minutes ago. */

const CONTACT_PASSWORD = 'Tr0ub4dor-and-3-horses';

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    Notification::fake();
    $this->amina = User::factory()->verified()->create(['email' => 'amina@example.com', 'phone' => '+8801712345678', 'password' => CONTACT_PASSWORD]);
    $this->signIn = function (?User $u = null) {
        auth()->forgetGuards();

        return ['Authorization' => 'Bearer '.app(IssueApiSession::class)->start($u ?? $this->amina, 'Pixel', 'android', 'password')->accessToken];
    };
    $this->confirmed = function (?User $u = null) {
        $h = ($this->signIn)($u);
        auth()->forgetGuards();
        test()->postJson('/api/v1/auth/reauth', ['password' => CONTACT_PASSWORD], $h)->assertNoContent();

        return $h;
    };
});

describe('the fresh confirmation', function () {
    it('is needed for every change, and not for sending the verification email again', function () {
        $h = ($this->signIn)();

        $this->postJson('/api/v1/me/email', ['email' => 'new@example.com'], $h)->assertStatus(403)->assertJsonPath('code', 'auth.reauth_required');
        $this->postJson('/api/v1/me/phone/code', ['phone' => '01812345678'], $h)->assertStatus(403)->assertJsonPath('code', 'auth.reauth_required');
        $this->postJson('/api/v1/me/phone/confirm', ['phone' => '01812345678', 'code' => '123456'], $h)->assertStatus(403)->assertJsonPath('code', 'auth.reauth_required');
        $this->postJson('/api/v1/me/email/resend-verification', [], $h)->assertStatus(202);
        Notification::assertNothingSentTo($this->amina, ConfirmNewEmailNotification::class);
    });

    it('belongs to one sign-in and lasts ten minutes', function () {
        $confirmed = ($this->confirmed)();
        $other = ($this->signIn)();

        $this->postJson('/api/v1/me/email', ['email' => 'new@example.com'], $confirmed)->assertStatus(202);
        auth()->forgetGuards(); // the test client keeps the guard's last answer between calls; a real server starts every request clean
        $this->postJson('/api/v1/me/email', ['email' => 'new@example.com'], $other)->assertStatus(403);
        auth()->forgetGuards();
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/me/email', ['email' => 'new@example.com'], $confirmed)->assertStatus(403)->assertJsonPath('code', 'auth.reauth_required');
    });

    it('needs a signed-in customer', function () {
        $this->postJson('/api/v1/me/email', ['email' => 'new@example.com'])->assertStatus(401);
        auth()->forgetGuards();
        $staff = User::factory()->staff()->create();
        $this->postJson('/api/v1/me/phone/code', ['phone' => '01812345678'], ['Authorization' => 'Bearer '.$staff->createToken('x', ['customer'], now()->addHour())->plainTextToken])->assertStatus(401);
    });
});

describe('the email address', function () {
    it('sends a link to the NEW address only, changes nothing yet, and answers the same whether or not the address is taken', function () {
        $h = ($this->confirmed)();
        User::factory()->verified()->create(['email' => 'taken@example.com']);

        $free = $this->postJson('/api/v1/me/email', ['email' => 'New@Example.com'], $h);
        $free->assertStatus(202)->assertJsonPath('data.status', 'check_your_email');
        assertMatchesOpenApi($free, 'POST', '/api/v1/me/email');
        Notification::assertSentOnDemand(ConfirmNewEmailNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'new@example.com');

        $taken = $this->postJson('/api/v1/me/email', ['email' => 'taken@example.com'], $h);
        expect($taken->status())->toBe(202)->and($taken->json())->toBe($free->json());
        Notification::assertSentTo(User::where('email', 'taken@example.com')->first(), AccountAlreadyExistsNotification::class);
        expect($this->amina->fresh()->email)->toBe('amina@example.com');
        $this->postJson('/api/v1/me/email', ['email' => 'not-an-email'], $h)->assertStatus(422)->assertJsonValidationErrors(['email']);
    });

    it('sends the verification email again only when the address is not verified yet', function () {
        $unverified = User::factory()->create(['email' => 'u@example.com', 'password' => CONTACT_PASSWORD]);
        $this->postJson('/api/v1/me/email/resend-verification', [], ($this->signIn)($unverified))->assertStatus(202);
        Notification::assertSentTo($unverified, VerifyEmailNotification::class);

        $this->postJson('/api/v1/me/email/resend-verification', [], ($this->signIn)())->assertStatus(202);
        Notification::assertNotSentTo($this->amina, VerifyEmailNotification::class);
    });
});

describe('the phone number', function () {
    it('sends a code to the new number, and changes the number only when the right code comes back', function () {
        $h = ($this->confirmed)();
        $sms = app(FakeSmsGateway::class);

        $this->postJson('/api/v1/me/phone/code', ['phone' => '01812345678'], $h)->assertStatus(202)->assertJsonPath('data.status', 'sent');
        expect($sms->sent()[0]['to'])->toBe('+8801812345678');

        $this->postJson('/api/v1/me/phone/confirm', ['phone' => '01812345678', 'code' => '000000'], $h)->assertStatus(401)->assertJsonPath('code', 'auth.otp_invalid');
        expect($this->amina->fresh()->phone)->toBe('+8801712345678');

        $this->postJson('/api/v1/me/phone/confirm', ['phone' => '01812345678', 'code' => (string) $sms->lastCode()], $h)->assertNoContent();
        $fresh = $this->amina->fresh();
        expect($fresh->phone)->toBe('+8801812345678')->and($fresh->phone_verified_at)->not->toBeNull();
        expect(DB::table('audit_events')->where('action', 'auth.phone_changed')->count())->toBe(1);
    });

    it('does not let a code sent to one number change another, or a number that belongs to somebody else', function () {
        $h = ($this->confirmed)();
        $sms = app(FakeSmsGateway::class);
        User::factory()->verified()->create(['email' => 'bob@example.com', 'phone' => '+8801911111111']);

        $this->postJson('/api/v1/me/phone/code', ['phone' => '01812345678'], $h)->assertStatus(202);
        $code = (string) $sms->lastCode();
        $this->postJson('/api/v1/me/phone/confirm', ['phone' => '01712999999', 'code' => $code], $h)->assertStatus(401);

        $this->postJson('/api/v1/me/phone/code', ['phone' => '01911111111'], $h)->assertStatus(202);
        $this->postJson('/api/v1/me/phone/confirm', ['phone' => '01911111111', 'code' => (string) $sms->lastCode()], $h)->assertStatus(422)->assertJsonValidationErrors(['phone']);
        expect($this->amina->fresh()->phone)->toBe('+8801712345678');
    });

    it('checks the number and says when a code was only just sent', function () {
        $h = ($this->confirmed)();

        $this->postJson('/api/v1/me/phone/code', ['phone' => 'banana'], $h)->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->postJson('/api/v1/me/phone/code', ['phone' => '01812345678'], $h)->assertStatus(202)->assertJsonPath('data.status', 'sent');
        $this->postJson('/api/v1/me/phone/code', ['phone' => '01812345678'], $h)->assertStatus(202)->assertJsonPath('data.status', 'cooling_down');
    });
});
