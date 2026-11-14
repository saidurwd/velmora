<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/*
 * What the audit trail keeps about signing in and out, for login history and security events (decision: owner, November 2026).
 * A failed password on a real account is tied to that account; one on an address with no account is not (there is nothing to tie it to).
 * Either way the visitor sees exactly the same answer, so the record never tells anyone whether an account exists.
 */

beforeEach(function () {
    RateLimiter::clear('x');
});

function lastAudit(string $action): ?stdClass
{
    return DB::table('audit_events')->where('action', $action)->orderByDesc('id')->first();
}

it('ties a failed password to the account it was tried on, and leaves an unknown address untied, with the same answer to the visitor', function () {
    $user = User::factory()->create(['email' => 'amina@example.com']);

    $known = $this->post('/en/login', ['identifier' => 'amina@example.com', 'password' => 'wrong-password-1']);
    $knownErrors = session('errors');
    $knownRow = lastAudit('auth.login_failed');
    $unknown = $this->post('/en/login', ['identifier' => 'nobody@example.com', 'password' => 'wrong-password-1']);
    $unknownErrors = session('errors');
    $unknownRow = lastAudit('auth.login_failed');

    expect($knownRow->subject_type)->toBe($user->getMorphClass())->and($knownRow->subject_id)->toBe($user->public_id)
        ->and($knownRow->actor_type)->toBe('guest')
        ->and($unknownRow->subject_type)->toBeNull()->and($unknownRow->subject_id)->toBeNull()
        ->and(json_decode((string) $unknownRow->after, true))->toMatchArray(['known' => false]);

    expect($knownErrors)->not->toBeEmpty()->toBe($unknownErrors)   // the visitor learns nothing from the difference
        ->and($known->status())->toBe($unknown->status())->and($known->headers->get('Location'))->toBe($unknown->headers->get('Location'));
});

it('ties a failed staff password to the staff account', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $staff = User::factory()->staff()->create(['email' => 'staff@example.com']);

    $this->post('/admin/login', ['email' => 'staff@example.com', 'password' => 'wrong-password-1']);

    expect(lastAudit('auth.login_failed'))->subject_id->toBe($staff->public_id);
});

it('records a customer signing out of the website and a staff member signing out of the staff area', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);   // a test sign-in has no device row, which the policy would end before the sign-out runs
    $customer = User::factory()->create();
    $this->actingAs($customer, 'web')->post('/en/logout')->assertRedirect();
    $row = lastAudit('auth.logout');
    expect($row)->not->toBeNull()->and($row->subject_id)->toBe($customer->public_id)->and($row->actor_id)->toBe($customer->id)->and($row->actor_type)->toBe('customer')
        ->and(json_decode((string) $row->after, true))->toBe(['area' => 'shop']);
    $this->assertGuest('web');

    $staff = catalogStaff(['catalog.view']);
    $this->actingAs($staff, 'admin')->post('/admin/logout')->assertRedirect('/admin/login');
    $row = lastAudit('auth.logout');
    expect($row->subject_id)->toBe($staff->public_id)->and($row->actor_type)->toBe('staff')->and(json_decode((string) $row->after, true))->toBe(['area' => 'staff']);
    $this->assertGuest('admin');
});
