<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Notifications\AccountAlreadyExistsNotification;
use App\Modules\IdentityAccess\Notifications\ConfirmNewEmailNotification;
use App\Modules\IdentityAccess\Notifications\SecurityChangeNotification;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\FrozenClock;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->sms = app(FakeSmsGateway::class);
});

/** Signs a customer in through the real form and carries the session, like a browser. */
function signedIn(object $test, array $attrs = []): User
{
    $user = User::factory()->create($attrs + ['email' => 'amina@example.com']);
    carrySession($test, $test->post('/en/login', ['identifier' => $user->email, 'password' => GOOD_PASSWORD]));

    return $user;
}

function confirmRecent(object $test): void
{
    carrySession($test, $test->post('/en/account/confirm', ['password' => GOOD_PASSWORD]));
}

describe('recent re-authentication', function () {
    it('asks for a password before showing the security page and returns there afterwards', function () {
        signedIn($this);

        $this->get('/en/account/security')->assertRedirect('/en/account/confirm');
        $this->get('/en/account/confirm')->assertOk()->assertSee('Confirm it is you');

        $this->post('/en/account/confirm', ['password' => GOOD_PASSWORD])->assertRedirect('/en/account/security');
    });

    it('rejects a wrong password and throttles guesses', function () {
        signedIn($this);

        foreach (range(1, 5) as $i) {
            $this->from('/en/account/confirm')->post('/en/account/confirm', ['password' => 'wrong-wrong-wrong'])->assertSessionHasErrors('password');
        }

        $this->from('/en/account/confirm')->post('/en/account/confirm', ['password' => GOOD_PASSWORD])->assertSessionHasErrors('password'); // locked out of guessing
    });

    it('expires after ten minutes', function () {
        $clock = new FrozenClock('2026-06-01 10:00:00');
        app()->instance(Clock::class, $clock);
        signedIn($this);
        confirmRecent($this);

        $this->get('/en/account/security')->assertOk();

        $clock->advanceSeconds(11 * 60);
        $this->get('/en/account/security')->assertRedirect('/en/account/confirm');
    });

    it('lets phone-only accounts confirm with an SMS code', function () {
        $user = User::factory()->phoneOnly('+8801712345678')->create();
        $user->forceFill(['phone_verified_at' => now()])->save();
        $this->actingAs($user, 'web');
        $this->get('/en/account/confirm')->assertOk()->assertDontSee('name="password"', false);

        carrySession($this, $this->post('/en/account/confirm/send-code'));
        $this->post('/en/account/confirm/code', ['code' => $this->sms->lastCode()])->assertRedirect('/en/account/security');
    });

    it('protects every sensitive POST, not just the page', function () {
        signedIn($this);

        foreach ([['put', '/en/account/security/password', ['password' => 'A-brand-new-Passphrase-77', 'password_confirmation' => 'A-brand-new-Passphrase-77', 'current_password' => GOOD_PASSWORD]],
            ['post', '/en/account/security/email', ['email' => 'x@example.com']],
            ['post', '/en/account/security/phone', ['phone' => '01711111111']],
            ['post', '/en/account/delete-request', []]] as [$method, $url, $data]) {
            $this->{$method}($url, $data)->assertRedirect('/en/account/confirm');
        }

        expect(DB::table('audit_events')->whereIn('action', ['auth.password_changed', 'account.deletion_requested'])->count())->toBe(0);
    });
});

describe('password', function () {
    it('changes the password, signs out other devices and alerts the owner', function () {
        $user = signedIn($this);
        confirmRecent($this);
        DB::table('user_sessions')->insert(['user_id' => $user->id, 'session_id_hash' => str_repeat('d', 64), 'session_id_encrypted' => Crypt::encryptString('other'), 'guard' => 'web', 'last_seen_at' => now(), 'created_at' => now()]);

        $this->put('/en/account/security/password', ['current_password' => GOOD_PASSWORD, 'password' => 'A-brand-new-Passphrase-77', 'password_confirmation' => 'A-brand-new-Passphrase-77'])->assertSessionHas('status');

        expect(Hash::check('A-brand-new-Passphrase-77', $user->fresh()->password))->toBeTrue()
            ->and(DB::table('user_sessions')->where('user_id', $user->id)->count())->toBe(1) // only this device remains
            ->and(DB::table('audit_events')->where('action', 'auth.password_changed')->count())->toBe(1);
        Notification::assertSentTo($user, SecurityChangeNotification::class);
    });

    it('requires the current password and a strong new one', function () {
        signedIn($this);
        confirmRecent($this);

        $this->from('/en/account/security')->put('/en/account/security/password', ['current_password' => 'wrong-wrong-wrong', 'password' => 'A-brand-new-Passphrase-77', 'password_confirmation' => 'A-brand-new-Passphrase-77'])->assertSessionHasErrors('current_password');
        $this->from('/en/account/security')->put('/en/account/security/password', ['current_password' => GOOD_PASSWORD, 'password' => 'password123', 'password_confirmation' => 'password123'])->assertSessionHasErrors('password');
    });

    it('lets a phone-only customer set a first password without a current one', function () {
        $user = User::factory()->phoneOnly('+8801712345678')->create();
        $user->forceFill(['phone_verified_at' => now()])->save();
        $this->actingAs($user, 'web');
        $this->withSession(['auth.recently_confirmed_at' => now()->getTimestamp()]);

        $this->put('/en/account/security/password', ['password' => 'A-brand-new-Passphrase-77', 'password_confirmation' => 'A-brand-new-Passphrase-77'])->assertSessionHas('status');

        expect($user->fresh()->password)->not->toBeNull()
            ->and(DB::table('audit_events')->where('action', 'auth.password_set')->count())->toBe(1);
    });
});

describe('email change', function () {
    it('mails a signed link to the NEW address and changes it only after the link is used', function () {
        $user = signedIn($this);
        confirmRecent($this);

        $this->post('/en/account/security/email', ['email' => 'New@Example.com'])->assertSessionHas('status');

        expect($user->fresh()->email)->toBe('amina@example.com'); // not yet
        Notification::assertSentTo(new AnonymousNotifiable, ConfirmNewEmailNotification::class, function ($n, $channels, $notifiable) {
            expect($notifiable->routes['mail'])->toBe('new@example.com');
            $url = $n->toMail($notifiable)->actionUrl;
            $this->get($url)->assertRedirect('/en/account/security')->assertSessionHas('status');

            return str_contains($url, 'signature=');
        });

        expect($user->fresh()->email)->toBe('new@example.com')->and($user->fresh()->email_verified_at)->not->toBeNull();
        Notification::assertSentTo(new AnonymousNotifiable, SecurityChangeNotification::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'amina@example.com'); // the OLD address is told
    });

    it('answers the same when the new address already belongs to someone else (no enumeration)', function () {
        $other = User::factory()->create(['email' => 'taken@example.com']);
        signedIn($this);
        confirmRecent($this);

        $free = $this->post('/en/account/security/email', ['email' => 'free@example.com']);
        $taken = $this->post('/en/account/security/email', ['email' => 'taken@example.com']);

        expect($taken->status())->toBe($free->status())->and(session('status'))->toBe(__('identity-access::account.email.sent'));
        Notification::assertSentTo($other, AccountAlreadyExistsNotification::class);
    });

    it('rejects a tampered link, another person\'s link and a stale link', function () {
        $user = signedIn($this);
        confirmRecent($this);
        $mk = fn (string $new, string $old, ?string $id = null) => URL::temporarySignedRoute('account.email.confirm', now()->addHour(), ['locale' => 'en', 'user' => $id ?? $user->public_id, 'new' => rtrim(strtr(base64_encode($new), '+/', '-_'), '='), 'old' => $old]);

        $this->get($mk('x@example.com', sha1('amina@example.com')).'x')->assertForbidden();                   // tampered signature
        $this->get($mk('x@example.com', sha1('amina@example.com'), 'someoneelse'))->assertRedirect();           // not the account owner
        $this->get($mk('x@example.com', sha1('old-address@example.com')))->assertRedirect();                    // bound to a different current address

        expect($user->fresh()->email)->toBe('amina@example.com');
    });

    it('cannot be replayed to flip the address back', function () {
        $user = signedIn($this);
        $first = URL::temporarySignedRoute('account.email.confirm', now()->addHour(), ['locale' => 'en', 'user' => $user->public_id, 'new' => rtrim(strtr(base64_encode('b@example.com'), '+/', '-_'), '='), 'old' => sha1('amina@example.com')]);

        $this->get($first);
        expect($user->fresh()->email)->toBe('b@example.com');

        $user->forceFill(['email' => 'c@example.com'])->save();
        $this->get($first); // the old link names amina@... as the current address: no longer valid

        expect($user->fresh()->email)->toBe('c@example.com');
    });
});

describe('phone change', function () {
    it('proves the new number with a code before swapping it', function () {
        $user = signedIn($this);
        confirmRecent($this);

        carrySession($this, $this->post('/en/account/security/phone', ['phone' => '01812345678']));
        expect($user->fresh()->phone)->toBeNull();

        $this->post('/en/account/security/phone/confirm', ['code' => $this->sms->lastCode()])->assertSessionHas('status');

        expect($user->fresh()->phone)->toBe('+8801812345678')->and($user->fresh()->phone_verified_at)->not->toBeNull();
        expect($this->sms->sent()[0]['to'])->toBe('+8801812345678'); // the code went to the NEW number
    });

    it('rejects a wrong code and a number already used by another account', function () {
        User::factory()->phoneOnly('+8801812345678')->create();
        $user = signedIn($this);
        confirmRecent($this);

        carrySession($this, $this->post('/en/account/security/phone', ['phone' => '01812345678']));
        $wrong = $this->sms->lastCode() === '000000' ? '111111' : '000000';
        $this->from('/en/account/security')->post('/en/account/security/phone/confirm', ['code' => $wrong])->assertSessionHasErrors('code');

        $this->post('/en/account/security/phone/confirm', ['code' => $this->sms->lastCode()])->assertSessionHasErrors('phone');
        expect($user->fresh()->phone)->toBeNull();
    });
});

describe('profile', function () {
    it('saves name, birthday and marketing consent with a consent timestamp', function () {
        $user = signedIn($this);

        $this->put('/en/account/profile', ['name' => 'আমিনা রহমান', 'birth_date' => '১৯৯০-০৫-১৫', 'marketing_consent' => '1'])->assertSessionHas('status');

        $profile = DB::table('customers')->where('user_id', $user->id)->first();
        expect($profile->name)->toBe('আমিনা রহমান')->and($profile->birth_date)->toBe('1990-05-15')->and((bool) $profile->marketing_consent)->toBeTrue()->and($profile->marketing_consent_at)->not->toBeNull();

        $this->put('/en/account/profile', ['name' => 'Amina'])->assertSessionHas('status');
        expect((bool) DB::table('customers')->where('user_id', $user->id)->value('marketing_consent'))->toBeFalse(); // unticked = withdrawn
    });

    it('rejects future dates and missing names', function () {
        signedIn($this);

        $this->from('/en/account/profile')->put('/en/account/profile', ['name' => '', 'birth_date' => '2999-01-01'])->assertSessionHasErrors(['name', 'birth_date']);
    });

    it('only ever touches the signed-in person\'s profile', function () {
        $other = User::factory()->create(['email' => 'other@example.com']);
        DB::table('customers')->insert(['public_id' => strtolower((string) Str::ulid()), 'user_id' => $other->id, 'name' => 'Other Person', 'created_at' => now(), 'updated_at' => now()]);
        $me = signedIn($this);

        $this->put('/en/account/profile', ['name' => 'Me Myself', 'user_id' => $other->id, 'customer_id' => 1]);

        expect(DB::table('customers')->where('user_id', $other->id)->value('name'))->toBe('Other Person')
            ->and(DB::table('customers')->where('user_id', $me->id)->value('name'))->toBe('Me Myself');
    });

    it('renders in Bangla', function () {
        signedIn($this, ['locale' => 'bn']);

        $this->get('/bn/account/profile')->assertOk()->assertSee('আমার প্রোফাইল')->assertSee('পূর্ণ নাম');
    });
});

describe('devices and deletion', function () {
    it('signs out all other devices but keeps this one', function () {
        $user = signedIn($this);
        DB::table('user_sessions')->insert(['user_id' => $user->id, 'session_id_hash' => str_repeat('e', 64), 'session_id_encrypted' => Crypt::encryptString('phone'), 'guard' => 'web', 'last_seen_at' => now(), 'created_at' => now()]);

        $this->post('/en/account/security/sessions/revoke-others')->assertSessionHas('status');

        expect(DB::table('user_sessions')->where('user_id', $user->id)->count())->toBe(1);
    });

    it('records an account deletion request (the workflow itself arrives in Phase 9)', function () {
        $user = signedIn($this);
        confirmRecent($this);

        $this->post('/en/account/delete-request')->assertSessionHas('status');

        expect(DB::table('audit_events')->where('action', 'account.deletion_requested')->where('subject_id', $user->public_id)->count())->toBe(1)
            ->and(User::find($user->id))->not->toBeNull(); // nothing is deleted automatically
    });
});

describe('mail', function () {
    it('renders the security alerts in the recipient language', function () {
        $user = User::factory()->create(['locale' => 'bn']);

        $mail = (new SecurityChangeNotification('password'))->toMail($user);

        expect($mail->subject)->toContain('পাসওয়ার্ড পরিবর্তন');
    });
});
