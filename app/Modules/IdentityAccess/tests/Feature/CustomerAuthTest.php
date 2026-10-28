<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Notifications\AccountAlreadyExistsNotification;
use App\Modules\IdentityAccess\Notifications\ResetPasswordNotification;
use App\Modules\IdentityAccess\Notifications\VerifyEmailNotification;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Outbox\OutboxDispatcher;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\FrozenClock;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    RateLimiter::clear('x');
    Notification::fake();
});

function registerPayload(array $over = []): array
{
    return array_merge(['name' => 'Amina Rahman', 'email' => 'amina@example.com', 'password' => 'Tr0ub4dor-and-3-horses', 'password_confirmation' => 'Tr0ub4dor-and-3-horses'], $over);
}

describe('pages', function () {
    it('renders the sign-in and register pages in both languages', function () {
        $this->get('/en/login')->assertOk()->assertSee('Sign in to your account')->assertSee('lang="en"', false);
        $this->get('/bn/login')->assertOk()->assertSee('আপনার অ্যাকাউন্টে সাইন ইন করুন')->assertSee('lang="bn"', false);
        $this->get('/bn/register')->assertOk()->assertSee('অ্যাকাউন্ট খুলুন');
    });

    it('sends guests to the sign-in page in their language and brings them back', function () {
        $this->get('/bn/account')->assertRedirect('/bn/login');
        $this->get('/en/account')->assertRedirect('/en/login');
    });

    it('keeps signed-in customers out of the guest pages', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/en/login')->assertRedirect('/en/account');
    });
});

describe('registration with email', function () {
    it('creates a customer, emails a verification link and creates the profile through the outbox', function () {
        $this->post('/en/register', registerPayload())->assertRedirect('/en/register/check-your-email');

        $user = User::where('email', 'amina@example.com')->firstOrFail();

        app(OutboxDispatcher::class)->dispatchDue(); // delivery normally happens after commit / by the scheduler

        expect($user->account_type->value)->toBe('customer')
            ->and($user->email_verified_at)->toBeNull()
            ->and($user->password)->toStartWith('$argon2id$')
            ->and(DB::table('customers')->where('user_id', $user->id)->value('name'))->toBe('Amina Rahman');

        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->assertGuest('web');
    });

    it('gives the same response for an already registered address and mails the owner instead (no enumeration)', function () {
        $existing = User::factory()->create(['email' => 'amina@example.com']);

        $new = $this->post('/en/register', registerPayload(['email' => 'fresh@example.com']));
        $dup = $this->post('/en/register', registerPayload());

        expect($dup->status())->toBe($new->status())
            ->and($dup->headers->get('Location'))->toBe($new->headers->get('Location'))
            ->and(User::where('email', 'amina@example.com')->count())->toBe(1);

        Notification::assertSentTo($existing, AccountAlreadyExistsNotification::class);
        Notification::assertNotSentTo($existing, VerifyEmailNotification::class);
    });

    it('rejects weak or mismatched passwords with translated messages', function (string $password, ?string $confirm) {
        $this->from('/en/register')->post('/en/register', registerPayload(['password' => $password, 'password_confirmation' => $confirm ?? $password]))
            ->assertRedirect('/en/register')->assertSessionHasErrors('password');

        expect(User::count())->toBe(0);
    })->with([
        'too short' => ['short1', null],
        'common' => ['password123', null],
        'low variety' => ['aaaaaaaaaaaa', null],
        'contains email name' => ['amina-is-great-1', null],
        'mismatch' => ['Tr0ub4dor-and-3-horses', 'different-value-here'],
    ]);

    it('shows validation errors in Bangla', function () {
        $this->followingRedirects()->from('/bn/register')->post('/bn/register', registerPayload(['name' => '']))
            ->assertSee('নাম আবশ্যক')->assertSee('অনুগ্রহ করে নিচের সমস্যাগুলো ঠিক করুন');
    });

    it('cannot be used to set privileged fields', function () {
        $this->post('/en/register', registerPayload(['account_type' => 'staff', 'status' => 'disabled', 'email_verified_at' => now()->toDateTimeString()]));

        $user = User::where('email', 'amina@example.com')->firstOrFail();
        expect($user->account_type->value)->toBe('customer')->and($user->status->value)->toBe('active')->and($user->email_verified_at)->toBeNull();
    });
});

describe('email verification', function () {
    it('verifies the address and signs the person in on first use of the signed link', function () {
        $user = User::factory()->create(['email' => 'amina@example.com']);
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['locale' => 'en', 'id' => $user->public_id, 'hash' => sha1('amina@example.com')]);

        $this->get($url)->assertRedirect('/en/account');

        expect($user->fresh()->email_verified_at)->not->toBeNull();
        $this->assertAuthenticatedAs($user, 'web');
    });

    it('does not sign in again when the link is reused', function () {
        $user = User::factory()->verified()->create(['email' => 'amina@example.com']);
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['locale' => 'en', 'id' => $user->public_id, 'hash' => sha1('amina@example.com')]);

        $this->get($url)->assertRedirect('/en/login');
        $this->assertGuest('web');
    });

    it('rejects tampered, expired or wrong-hash links', function () {
        $user = User::factory()->create(['email' => 'amina@example.com']);
        $good = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['locale' => 'en', 'id' => $user->public_id, 'hash' => sha1('amina@example.com')]);

        $this->get($good.'x')->assertForbidden();
        $this->get(URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['locale' => 'en', 'id' => $user->public_id, 'hash' => sha1('amina@example.com')]))->assertForbidden();
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['locale' => 'en', 'id' => $user->public_id, 'hash' => sha1('other@example.com')]))->assertRedirect('/en/login');

        expect($user->fresh()->email_verified_at)->toBeNull();
    });
});

describe('password sign-in', function () {
    it('signs in with email and password and records the session', function () {
        $user = User::factory()->create(['email' => 'amina@example.com']);

        $this->post('/en/login', ['identifier' => 'AMINA@example.com', 'password' => GOOD_PASSWORD])->assertRedirect('/en/account');

        $this->assertAuthenticatedAs($user, 'web');
        expect(DB::table('user_sessions')->where('user_id', $user->id)->where('guard', 'web')->count())->toBe(1)
            ->and($user->fresh()->last_login_at)->not->toBeNull()
            ->and(DB::table('audit_events')->where('action', 'auth.login')->count())->toBe(1);
    });

    it('signs in with a Bangladeshi phone number and password, in any format', function (string $typed) {
        $user = User::factory()->create(['email' => null, 'phone' => '+8801712345678']);

        $this->post('/en/login', ['identifier' => $typed, 'password' => GOOD_PASSWORD])->assertRedirect('/en/account');
        $this->assertAuthenticatedAs($user, 'web');
    })->with(['01712345678', '+8801712345678', '০১৭১২৩৪৫৬৭৮']);

    it('gives one identical error for wrong password, unknown account, disabled and locked accounts', function () {
        User::factory()->create(['email' => 'amina@example.com']);
        User::factory()->status(UserStatus::Disabled)->create(['email' => 'off@example.com']);

        $messages = [];
        foreach ([['amina@example.com', 'wrong-password-123'], ['nobody@example.com', GOOD_PASSWORD], ['off@example.com', GOOD_PASSWORD]] as [$id, $pw]) {
            RateLimiter::clear('x');
            $messages[] = $this->from('/en/login')->post('/en/login', ['identifier' => $id, 'password' => $pw])
                ->assertRedirect('/en/login')->assertSessionHasErrors('identifier')->assertSessionDoesntHaveErrors('password');
        }

        $texts = array_map(fn ($r) => session('errors')->first('identifier'), [1]);
        expect(session('errors')->first('identifier'))->toBe(__('auth.failed'));
        $this->assertGuest('web');
    });

    it('throttles repeated attempts per identifier and IP', function () {
        User::factory()->create(['email' => 'amina@example.com']);

        foreach (range(1, 5) as $i) {
            $this->post('/en/login', ['identifier' => 'amina@example.com', 'password' => 'wrong-wrong-wrong']);
        }

        // even the CORRECT password is refused while throttled
        $this->from('/en/login')->post('/en/login', ['identifier' => 'amina@example.com', 'password' => GOOD_PASSWORD])
            ->assertSessionHasErrors('identifier');
        $this->assertGuest('web');
    });

    it('locks the account after 10 failures for 15 minutes, even for the right password', function () {
        $this->withoutMiddleware(ThrottleRequests::class); // route throttle is tested separately
        $clock = new FrozenClock('2026-06-01 10:00:00');
        app()->instance(Clock::class, $clock);
        $user = User::factory()->create(['email' => 'amina@example.com']);

        foreach (range(1, 10) as $i) {
            RateLimiter::clear('login:customer:'.sha1('amina@example.com|127.0.0.1'));
            $this->post('/en/login', ['identifier' => 'amina@example.com', 'password' => 'wrong-wrong-wrong']);
        }

        expect($user->fresh()->locked_until)->not->toBeNull()
            ->and(DB::table('audit_events')->where('action', 'auth.account_locked')->count())->toBe(1);

        RateLimiter::clear('login:customer:'.sha1('amina@example.com|127.0.0.1'));
        $this->post('/en/login', ['identifier' => 'amina@example.com', 'password' => GOOD_PASSWORD]);
        $this->assertGuest('web');

        $clock->advanceSeconds(16 * 60);
        RateLimiter::clear('login:customer:'.sha1('amina@example.com|127.0.0.1'));
        $this->post('/en/login', ['identifier' => 'amina@example.com', 'password' => GOOD_PASSWORD])->assertRedirect('/en/account');
    });

    it('does not let a staff account sign in through the customer form', function () {
        User::factory()->staff()->create(['email' => 'boss@example.com']);

        $this->post('/en/login', ['identifier' => 'boss@example.com', 'password' => GOOD_PASSWORD]);
        $this->assertGuest('web');
        $this->assertGuest('admin');
    });

    it('rotates the session id on sign in (session fixation)', function () {
        User::factory()->create(['email' => 'amina@example.com']);
        $this->get('/en/login');
        $before = session()->getId();

        $this->post('/en/login', ['identifier' => 'amina@example.com', 'password' => GOOD_PASSWORD]);

        expect(session()->getId())->not->toBe($before);
    });

    it('signs out and clears the session record', function () {
        $user = User::factory()->create();
        carrySession($this, $this->post('/en/login', ['identifier' => $user->email, 'password' => GOOD_PASSWORD]));
        expect(DB::table('user_sessions')->count())->toBe(1);

        $this->post('/en/logout')->assertRedirect('/en');

        $this->assertGuest('web');
        expect(DB::table('user_sessions')->count())->toBe(0);
    });

    it('rejects a session that was revoked from another device', function () {
        $user = User::factory()->create();
        carrySession($this, $this->post('/en/login', ['identifier' => $user->email, 'password' => GOOD_PASSWORD]));

        $this->get('/en/account')->assertOk(); // the live session works ...

        DB::table('user_sessions')->where('user_id', $user->id)->delete(); // ... until "log out other devices" removes its record

        $this->get('/en/account')->assertRedirect('/en/login');
        $this->assertGuest('web');
    });
});

describe('phone sign-in with a one-time code', function () {
    beforeEach(fn () => $this->sms = app(FakeSmsGateway::class));

    it('creates the account on first use and signs in with the code', function () {
        $this->post('/en/login/phone', ['phone' => '01712345678'])->assertRedirect('/en/login/phone/verify');
        $this->get('/en/login/phone/verify')->assertOk()->assertSee('+88017****5678');

        $this->post('/en/login/phone/verify', ['code' => $this->sms->lastCode()])->assertRedirect('/en/account');
        app(OutboxDispatcher::class)->dispatchDue();

        $user = User::where('phone', '+8801712345678')->firstOrFail();
        $this->assertAuthenticatedAs($user, 'web');
        expect($user->phone_verified_at)->not->toBeNull()->and($user->password)->toBeNull()
            ->and(DB::table('customers')->where('user_id', $user->id)->value('phone'))->toBe('+8801712345678');
    });

    it('signs an existing customer in without creating a second account', function () {
        $user = User::factory()->phoneOnly('+8801712345678')->create();

        $this->post('/en/login/phone', ['phone' => '+8801712345678']);
        $this->post('/en/login/phone/verify', ['code' => $this->sms->lastCode()]);

        $this->assertAuthenticatedAs($user, 'web');
        expect(User::count())->toBe(1);
    });

    it('treats known and unknown numbers identically (no enumeration)', function () {
        User::factory()->phoneOnly('+8801712345678')->create();

        $known = $this->post('/en/login/phone', ['phone' => '01712345678']);
        $unknown = $this->post('/en/login/phone', ['phone' => '01899999999']);

        expect($known->status())->toBe($unknown->status())->and($known->headers->get('Location'))->toBe($unknown->headers->get('Location'))
            ->and($this->sms->sent())->toHaveCount(2);
    });

    it('accepts the code typed in Bangla digits', function () {
        $this->post('/en/login/phone', ['phone' => '০১৭১২৩৪৫৬৭৮']);
        $code = BanglaText::digitsToBangla((string) $this->sms->lastCode());

        $this->post('/en/login/phone/verify', ['code' => $code])->assertRedirect('/en/account');
    });

    it('rejects a wrong code with a generic message and never signs in', function () {
        $this->post('/en/login/phone', ['phone' => '01712345678']);
        $wrong = $this->sms->lastCode() === '000000' ? '111111' : '000000';

        $this->from('/en/login/phone/verify')->post('/en/login/phone/verify', ['code' => $wrong])->assertSessionHasErrors('code');

        $this->assertGuest('web');
        expect(User::count())->toBe(0);
    });

    it('locks out after five wrong codes even if the right one follows', function () {
        $this->post('/en/login/phone', ['phone' => '01712345678']);
        $code = $this->sms->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 5) as $i) {
            RateLimiter::clear('x');
            $this->post('/en/login/phone/verify', ['code' => $wrong]);
        }

        $this->post('/en/login/phone/verify', ['code' => $code]);
        $this->assertGuest('web');
    });

    it('rejects an invalid number and does not send an SMS', function () {
        $this->from('/en/login/phone')->post('/en/login/phone', ['phone' => '12345'])->assertSessionHasErrors('phone');

        expect($this->sms->sent())->toBe([]);
    });

    it('sends the SMS in the visitor language', function () {
        $this->post('/bn/login/phone', ['phone' => '01712345678']);

        expect($this->sms->sent()[0]['message'])->toContain('কোড');
    });

    it('does not let the verify step be reached without requesting a code', function () {
        $this->get('/en/login/phone/verify')->assertRedirect('/en/login/phone');
        $this->post('/en/login/phone/verify', ['code' => '123456'])->assertRedirect('/en/login/phone');
    });
});

describe('password reset', function () {
    it('answers the same for known and unknown addresses but only mails the known one', function () {
        $user = User::factory()->create(['email' => 'amina@example.com']);

        $a = $this->from('/en/forgot-password')->post('/en/forgot-password', ['email' => 'amina@example.com']);
        $b = $this->from('/en/forgot-password')->post('/en/forgot-password', ['email' => 'nobody@example.com']);

        expect($a->status())->toBe($b->status())->and(session('status'))->toBe(__('identity-access::auth.forgot.sent'));
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertCount(1);
    });

    it('resets the password with a valid token, ends other sessions and requires a fresh sign in', function () {
        $user = User::factory()->create(['email' => 'amina@example.com']);
        $this->post('/en/login', ['identifier' => 'amina@example.com', 'password' => GOOD_PASSWORD]);
        $this->post('/en/logout');
        DB::table('user_sessions')->insert(['user_id' => $user->id, 'session_id_hash' => str_repeat('a', 64), 'session_id_encrypted' => Crypt::encryptString('abc'), 'guard' => 'web', 'last_seen_at' => now(), 'created_at' => now()]);

        $token = Password::broker('users')->createToken($user);

        $this->post('/en/reset-password', ['token' => $token, 'email' => 'amina@example.com', 'password' => 'A-brand-new-Passphrase-77', 'password_confirmation' => 'A-brand-new-Passphrase-77'])
            ->assertRedirect('/en/login');

        expect(Hash::check('A-brand-new-Passphrase-77', $user->fresh()->password))->toBeTrue()
            ->and(DB::table('user_sessions')->where('user_id', $user->id)->count())->toBe(0);
        $this->assertGuest('web');
    });

    it('rejects an invalid token, and a used token cannot be replayed', function () {
        $user = User::factory()->create(['email' => 'amina@example.com']);
        $token = Password::broker('users')->createToken($user);
        $payload = ['token' => $token, 'email' => 'amina@example.com', 'password' => 'A-brand-new-Passphrase-77', 'password_confirmation' => 'A-brand-new-Passphrase-77'];

        $this->from('/en/reset-password/x')->post('/en/reset-password', ['token' => 'bogus'] + $payload)->assertSessionHasErrors('email');

        $this->post('/en/reset-password', $payload)->assertRedirect('/en/login');
        $this->from('/en/reset-password/x')->post('/en/reset-password', $payload)->assertSessionHasErrors('email');
    });

    it('builds a working localized reset link for the email', function () {
        $user = User::factory()->create(['email' => 'amina@example.com', 'locale' => 'bn']);

        $mail = (new ResetPasswordNotification('tok123'))->toMail($user);

        expect($mail->actionUrl)->toContain('/bn/reset-password/tok123')->toContain('email=amina%40example.com');
    });
});

describe('emails render in the recipient language', function () {
    it('uses Bangla subject and text for a Bangla user', function () {
        $user = User::factory()->create(['locale' => 'bn']);

        app()->setLocale('bn');
        $mail = (new VerifyEmailNotification)->toMail($user);

        expect($mail->subject)->toContain('ইমেইল ঠিকানা যাচাই')->and(implode(' ', $mail->introLines))->toContain('অ্যাকাউন্ট');
    });

    it('delivers the notification in the user locale', function () {
        Notification::fake();
        $user = User::factory()->create(['locale' => 'bn']);

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
        expect($user->preferredLocale())->toBe('bn');
    });
});
