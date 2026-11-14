<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Actions\ChangePassword;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Notifications\SecurityChangeNotification;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/*
 * A signed-in staff member changes their own password from the staff area (Account menu → Change password). Owner-approved, November 2026.
 * The current password is the proof it is them; the new one follows the same rules as everywhere else; afterwards every other device is signed out,
 * a notice goes to their email, and the audit trail says a STAFF member did it.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    RateLimiter::clear('x');
    Notification::fake();
    $this->staff = catalogStaff(['catalog.view']);   // any staff member: it is their own password, no permission needed
    $this->staff->forceFill(['password' => GOOD_PASSWORD, 'email' => 'rahima.staff@example.com', 'name' => 'Rahima Akter'])->save();
    $this->as = fn () => $this->actingAs($this->staff->fresh(), 'admin');
    $this->change = fn (array $over = []) => ($this->as)()->put('/admin/account/password', $over + ['current_password' => GOOD_PASSWORD, 'password' => 'A-brand-new-Passphrase-77', 'password_confirmation' => 'A-brand-new-Passphrase-77']);
});

it('shows the page from the account menu, in English and Bangla', function () {
    ($this->as)()->get('/admin/account/password')->assertOk()->assertSee('Change password')->assertSee('Current password')->assertSee('name="password_confirmation"', false);
    ($this->as)()->get('/admin/account/sessions')->assertOk()->assertSee('/admin/account/password', false);   // the account menu links to it

    $this->staff->forceFill(['locale' => 'bn'])->save();
    ($this->as)()->get('/admin/account/password')->assertOk()->assertSee('পাসওয়ার্ড পরিবর্তন');
});

it('changes the password, signs out the other devices, emails a notice and records that a staff member did it', function () {
    DB::table('user_sessions')->insert(['user_id' => $this->staff->id, 'session_id_hash' => str_repeat('d', 64), 'session_id_encrypted' => Crypt::encryptString('other-laptop'), 'guard' => 'admin', 'last_seen_at' => now(), 'created_at' => now()]);

    ($this->change)()->assertRedirect('/admin/account/password')->assertSessionHas('status');

    $fresh = $this->staff->fresh();
    expect(Hash::check('A-brand-new-Passphrase-77', $fresh->password))->toBeTrue()->and(Hash::check(GOOD_PASSWORD, $fresh->password))->toBeFalse()
        ->and(DB::table('user_sessions')->where('session_id_hash', str_repeat('d', 64))->exists())->toBeFalse();   // the other device is signed out
    $this->assertAuthenticatedAs($fresh, 'admin');   // this one stays signed in
    Notification::assertSentTo($fresh, SecurityChangeNotification::class);

    $audit = DB::table('audit_events')->where('action', 'auth.password_changed')->sole();
    expect($audit->actor_type)->toBe('staff')->and((int) $audit->actor_id)->toBe($this->staff->id)->and($audit->subject_id)->toBe($this->staff->public_id);
});

it('refuses a wrong current password and changes nothing', function () {
    ($this->change)(['current_password' => 'not-my-password-1'])->assertSessionHasErrors('current_password');

    expect(Hash::check(GOOD_PASSWORD, $this->staff->fresh()->password))->toBeTrue()
        ->and(DB::table('audit_events')->where('action', 'auth.password_changed')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('refuses a weak, unconfirmed or account-derived new password', function (array $over, string $field) {
    ($this->change)($over)->assertSessionHasErrors($field);

    expect(Hash::check(GOOD_PASSWORD, $this->staff->fresh()->password))->toBeTrue();
})->with([
    'too short' => [['password' => 'short-1', 'password_confirmation' => 'short-1'], 'password'],
    'common' => [['password' => 'bangladesh123', 'password_confirmation' => 'bangladesh123'], 'password'],
    'from my own email' => [['password' => 'Rahima.staff-2026!', 'password_confirmation' => 'Rahima.staff-2026!'], 'password'],   // rahima.staff@example.com
    'not confirmed' => [['password_confirmation' => 'Something-else-entirely-9'], 'password'],
    'missing current' => [['current_password' => ''], 'current_password'],
]);

it('is only for a signed-in staff member, and limits how often it can be tried', function () {
    $this->get('/admin/account/password')->assertRedirect('/admin/login');
    $this->put('/admin/account/password', ['current_password' => 'x', 'password' => 'y', 'password_confirmation' => 'y'])->assertRedirect('/admin/login');

    foreach (range(1, 6) as $i) {
        ($this->change)(['current_password' => 'wrong-guess-'.$i]);
    }
    ($this->change)(['current_password' => 'wrong-guess-7'])->assertStatus(429);
});

it('still records a customer changing their own password as a customer', function () {
    $customer = User::factory()->create(['password' => GOOD_PASSWORD]);
    app(ChangePassword::class)->handle($customer, 'A-brand-new-Passphrase-77', 'session-id');

    expect(DB::table('audit_events')->where('action', 'auth.password_changed')->sole()->actor_type)->toBe('customer');
});
