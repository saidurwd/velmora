<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Security & audit screens: activity log, security events, login history and "recent sign-ins". All read the audit trail; names come from the accounts,
 * network addresses are shown in part only, and each page asks the database a fixed number of questions however many rows it shows.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->auditor = catalogStaff(['audit.view']);
    $this->auditor->forceFill(['name' => 'Nasrin Auditor'])->save();
    $this->rahima = catalogStaff(['catalog.view']);
    $this->rahima->forceFill(['name' => 'Rahima Akter'])->save();
    $this->amina = User::factory()->create(['name' => 'Amina Customer', 'email' => 'amina@example.com', 'phone' => '+8801712345678']);
    $this->morph = $this->amina->getMorphClass();

    // one row of the audit trail, as the system writes it
    $this->row = function (string $action, array $over = []): void {
        DB::table('audit_events')->insert($over + [
            'actor_type' => 'system', 'actor_id' => null, 'actor_label' => null, 'action' => $action, 'subject_type' => null, 'subject_id' => null,
            'before' => null, 'after' => null, 'ip' => '203.0.113.45', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36',
            'request_id' => null, 'created_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    };
    $this->as = fn (User $u) => $this->actingAs($u, 'admin');
});

describe('the activity log', function () {
    it('tells what staff did in plain words, leaves out sign-ins and customers, and filters by person and area', function () {
        ($this->row)('product.published', ['actor_type' => 'staff', 'actor_id' => $this->rahima->id]);
        ($this->row)('stock.adjusted', ['actor_type' => 'staff', 'actor_id' => $this->rahima->id]);
        ($this->row)('auth.login', ['actor_type' => 'staff', 'actor_id' => $this->rahima->id]);
        ($this->row)('reviews.submitted', ['actor_type' => 'customer', 'actor_id' => $this->amina->id]);

        $page = ($this->as)($this->auditor)->get('/admin/security/activity')->assertOk();
        $page->assertSee('Rahima Akter')->assertSee('put a product on sale')->assertSee('adjusted stock')
            ->assertDontSee('signed in')->assertDontSee('wrote a review');

        ($this->as)($this->auditor)->get('/admin/security/activity?area=inventory')->assertOk()->assertSee('adjusted stock')->assertDontSee('put a product on sale');
        ($this->as)($this->auditor)->get('/admin/security/activity?person='.$this->auditor->id)->assertOk()->assertDontSee('adjusted stock');
        ($this->as)($this->auditor)->get('/admin/security/activity?area=nonsense')->assertSessionHasErrors('area');
    });

    it('is only for those who may read the audit log, while every staff member sees their own activity', function () {
        ($this->row)('stock.adjusted', ['actor_type' => 'staff', 'actor_id' => $this->rahima->id]);
        ($this->row)('product.published', ['actor_type' => 'staff', 'actor_id' => $this->auditor->id]);

        ($this->as)($this->rahima)->get('/admin/security/activity')->assertForbidden();
        ($this->as)($this->rahima)->get('/admin/account/activity')->assertOk()->assertSee('My activity')->assertSee('adjusted stock')->assertDontSee('put a product on sale')
            ->assertDontSee('/admin/audit/', false);   // no link into the audit log for someone who may not read it
    });

    it('reads in Bangla', function () {
        ($this->row)('stock.adjusted', ['actor_type' => 'staff', 'actor_id' => $this->rahima->id]);
        $this->auditor->forceFill(['locale' => 'bn'])->save();

        ($this->as)($this->auditor->fresh())->get('/admin/security/activity')->assertOk()->assertSee('অ্যাক্টিভিটি লগ')->assertSee('স্টক সমন্বয় করেছেন');
    });
});

describe('security events', function () {
    it('counts the last day, names the busiest addresses only in part, and rates each event', function () {
        foreach (range(1, 3) as $i) {
            ($this->row)('auth.login_failed', ['actor_type' => 'guest', 'subject_type' => $this->morph, 'subject_id' => $this->amina->public_id]);
        }
        ($this->row)('auth.account_locked', ['subject_type' => $this->morph, 'subject_id' => $this->amina->public_id]);
        ($this->row)('auth.login_failed', ['actor_type' => 'guest', 'created_at' => now()->subDays(2)->format('Y-m-d H:i:s.u')]);   // too old for the summary
        ($this->row)('role.deleted', ['actor_type' => 'staff', 'actor_id' => $this->auditor->id]);

        $html = (string) ($this->as)($this->auditor)->get('/admin/security/events')->assertOk()->getContent();

        expect($html)->toContain('203.0.113.x')->not->toContain('203.0.113.45')   // shown in part only
            ->toContain('3 failed attempts')->toContain('High')->toContain('Amina Customer')->toContain('deleted a staff role');
        ($this->as)($this->auditor)->get('/admin/security/events?severity=high')->assertOk()->assertSee('deleted a staff role')->assertDontSee('failed to sign in (wrong password)');
        ($this->as)($this->rahima)->get('/admin/security/events')->assertForbidden();
    });
});

describe('login history', function () {
    it('shows sign-ins, sign-outs and failures with the person, how and where, and the device in plain words', function () {
        ($this->row)('auth.login', ['actor_type' => 'staff', 'actor_id' => $this->rahima->id, 'subject_type' => $this->morph, 'subject_id' => $this->rahima->public_id]);
        ($this->row)('auth.login_failed', ['actor_type' => 'guest', 'subject_type' => $this->morph, 'subject_id' => $this->amina->public_id, 'after' => '{"known":true}']);
        ($this->row)('auth.login_failed', ['actor_type' => 'guest', 'after' => '{"known":false}']);
        ($this->row)('auth.logout', ['actor_type' => 'customer', 'actor_id' => $this->amina->id, 'subject_type' => $this->morph, 'subject_id' => $this->amina->public_id, 'after' => '{"area":"shop"}']);
        ($this->row)('auth.api_sessions_ended', ['subject_type' => 'users', 'subject_id' => (string) $this->amina->id]);   // the app's way of recording a person

        $html = (string) ($this->as)($this->auditor)->get('/admin/security/logins')->assertOk()->getContent();

        expect($html)->toContain('Rahima Akter')->toContain('Staff area')->toContain('Amina Customer')->toContain('Wrong password')->toContain('No such account')
            ->toContain('Chrome on Windows')->toContain('Mobile app')->toContain('203.0.113.x')->not->toContain('203.0.113.45');
    });

    it('filters by staff or customers, counting a failed password on a customer account (which has no actor) as the customer’s', function () {
        ($this->row)('auth.login', ['actor_type' => 'staff', 'actor_id' => $this->rahima->id, 'subject_type' => $this->morph, 'subject_id' => $this->rahima->public_id]);
        ($this->row)('auth.login_failed', ['actor_type' => 'guest', 'subject_type' => $this->morph, 'subject_id' => $this->amina->public_id]);

        // the rows only: the staff filter's own list names every staff member
        $rows = fn (string $query): string => (string) preg_replace('/^.*<tbody>(.*)<\/tbody>.*$/s', '$1', (string) ($this->as)($this->auditor)->get('/admin/security/logins'.$query)->assertOk()->getContent());

        expect($rows('?kind=staff'))->toContain('Rahima Akter')->not->toContain('Amina Customer')
            ->and($rows('?kind=customer'))->toContain('Amina Customer')->not->toContain('Rahima Akter')
            ->and($rows('?result=failure'))->toContain('Wrong password')->not->toContain('Rahima Akter');
    });

    it('finds one customer only by their exact email or phone, never by part of one', function () {
        ($this->row)('auth.login_failed', ['actor_type' => 'guest', 'subject_type' => $this->morph, 'subject_id' => $this->amina->public_id]);

        ($this->as)($this->auditor)->get('/admin/security/logins?customer=AMINA@example.com')->assertOk()->assertSee('Amina Customer');
        ($this->as)($this->auditor)->get('/admin/security/logins?customer=01712345678')->assertOk()->assertSee('Amina Customer');
        ($this->as)($this->auditor)->get('/admin/security/logins?customer=amina')->assertOk()->assertDontSee('Amina Customer')->assertSee('No customer has that exact email address or phone number.');
    });

    it('asks the database the same number of questions for 5 rows as for 40', function () {
        // the audit trail is append-only (a trigger refuses deletes), so add rows and measure again
        $add = function (int $n): void {
            foreach (range(1, $n) as $i) {
                $u = User::factory()->create();
                ($this->row)('auth.login', ['actor_type' => 'customer', 'actor_id' => $u->id, 'subject_type' => $this->morph, 'subject_id' => $u->public_id]);
            }
        };
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->as)($this->auditor)->get('/admin/security/logins')->assertOk();
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $q;
        };

        $add(5);
        ($this->as)($this->auditor)->get('/admin/security/logins');   // warm what is cached per request (permissions, settings)
        $few = $count();
        $add(35);
        $many = $count();
        expect($many)->toBe($few, "40 rows: {$many} queries, 5 rows: {$few}");
    });

    it('lists a staff member’s own recent sign-ins on their sessions page', function () {
        ($this->row)('auth.login_failed', ['actor_type' => 'guest', 'subject_type' => $this->morph, 'subject_id' => $this->rahima->public_id]);
        ($this->row)('auth.login_failed', ['actor_type' => 'guest', 'subject_type' => $this->morph, 'subject_id' => $this->amina->public_id, 'ip' => '198.51.100.7']);

        ($this->as)($this->rahima)->get('/admin/account/sessions')->assertOk()->assertSee('Recent sign-ins')->assertSee('Wrong password')->assertSee('203.0.113.x')
            ->assertDontSee('198.51.100.x');   // someone else's attempt is not on my page
    });
});
