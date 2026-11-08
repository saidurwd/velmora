<?php

declare(strict_types=1);

use App\Modules\Cms\Actions\AssignPolicyKind;
use App\Modules\Cms\Actions\PublishDuePages;
use App\Modules\Cms\Actions\SavePage;
use App\Modules\Cms\Actions\TransitionPage;
use App\Modules\Cms\Contracts\PolicyAcceptances;
use App\Modules\Cms\Database\Seeders\DemoCmsSeeder;
use App\Modules\Cms\Enums\PolicyKind;
use App\Modules\Cms\Exceptions\PolicyException;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Privacy\PolicyPersonalData;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\FrozenClock;
use App\Modules\Privacy\Services\PersonalDataCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 10, slice 3b: policy pages (terms, privacy, returns, shipping, COD), releases, and who accepted which release.
 */

beforeEach(function () {
    Notification::fake();
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->clock = new FrozenClock(CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC'));
    $this->travelTo(CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC'));
    $this->app->instance(Clock::class, $this->clock);
    $this->actor = Actor::system('t');
    $this->at = function (string $utc): void {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        $this->clock->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    };

    /** Makes (or edits) the page of a kind and publishes the new version. Returns the release id. */
    $this->release = function (string $kind, ?string $title = null, bool $required = true) {
        $page = Page::query()->where('kind', $kind)->first();
        $title ??= ucfirst($kind);
        if ($page === null) {
            $page = app(SavePage::class)->handle($this->actor, null, ['en' => ['title' => $title, 'slug' => $kind, 'body' => "<p>{$title} text</p>"], 'bn' => ['title' => $title.' বাংলা', 'body' => '<p>বাংলা</p>']]);
            app(AssignPolicyKind::class)->handle($this->actor, $page, PolicyKind::from($kind));
            $number = 1;
        } else {
            $base = (int) $page->draftVersion->number;
            $page = app(SavePage::class)->handle($this->actor, $page, ['en' => ['title' => $title, 'slug' => $kind, 'body' => "<p>{$title} text</p>"]], $base);
            $number = $base + 1;
        }
        app(TransitionPage::class)->publish($this->actor, $page, $number, $required);

        return (int) DB::table('cms_policy_releases')->where('kind', $kind)->max('id');
    };
    $this->policies = fn () => app(PolicyAcceptances::class);
    $this->open = fn (?int $userId, string $context = 'signup', ?string $pay = null) => array_map(fn ($n) => $n->kind, ($this->policies)()->outstanding($userId, $context, $pay, 'en'));
    $this->ids = fn (?int $userId, string $context = 'signup', ?string $pay = null) => array_map(fn ($n) => $n->releaseId, ($this->policies)()->outstanding($userId, $context, $pay, 'en'));
    $this->customer = fn (string $email = 'amina@example.com') => User::factory()->verified()->create(['email' => $email]);
    $this->manager = catalogStaff(['cms.view', 'cms.edit', 'cms.publish', 'cms.policies.manage', 'policies.acceptance.view']);
});

describe('releases', function () {
    it('makes a release when a policy version goes live, never for an ordinary page, and never twice for the same publish', function () {
        $ordinary = app(SavePage::class)->handle($this->actor, null, ['en' => ['title' => 'About', 'body' => '<p>x</p>']]);
        app(TransitionPage::class)->publish($this->actor, $ordinary, 1);
        expect(DB::table('cms_policy_releases')->count())->toBe(0);

        ($this->release)('terms', 'Terms v1');
        $page = Page::query()->where('kind', 'terms')->firstOrFail();
        app(TransitionPage::class)->publish($this->actor, $page, 1);   // again: nothing changes
        expect(DB::table('cms_policy_releases')->count())->toBe(1);

        ($this->release)('terms', 'Terms v2');
        expect(DB::table('cms_policy_releases')->count())->toBe(2);

        app(TransitionPage::class)->publish($this->actor, $page->fresh(), 1);   // a rollback is a release of its own
        expect(DB::table('cms_policy_releases')->orderBy('id')->pluck('version_id')->all())->toHaveCount(3)
            ->and(DB::table('cms_policy_releases')->orderBy('id')->get()->map(fn ($r) => DB::table('cms_page_versions')->where('id', $r->version_id)->value('number'))->all())->toBe([1, 2, 1])
            ->and(DB::table('cms_policy_releases')->value('effective_at'))->toBe('2026-12-01 06:00:00.000000');
    });

    it('always asks for acceptance on the first release; a later release may be a minor edit', function () {
        ($this->release)('terms', 'T1', false);
        ($this->release)('terms', 'T2', false);
        ($this->release)('terms', 'T3', true);

        expect(DB::table('cms_policy_releases')->orderBy('id')->pluck('acceptance_required')->map(fn ($v) => (bool) $v)->all())->toBe([true, false, true]);
    });

    it('makes the release when a scheduled version goes live, with the choice made when it was scheduled', function () {
        ($this->release)('terms', 'T1');
        $page = Page::query()->where('kind', 'terms')->firstOrFail();
        $saved = app(SavePage::class)->handle($this->actor, $page, ['en' => ['title' => 'T2', 'slug' => 'terms', 'body' => '<p>t2</p>']], 1);
        app(TransitionPage::class)->schedule($this->actor, $saved, 2, CarbonImmutable::parse('2026-12-02 06:00:00', 'UTC'), false);

        expect(DB::table('cms_policy_releases')->count())->toBe(1)->and((bool) $saved->fresh()->schedule_requires_acceptance)->toBeFalse();

        ($this->at)('2026-12-02 06:00:30');
        app(PublishDuePages::class)->handle();

        $second = DB::table('cms_policy_releases')->orderBy('id')->get()[1];
        expect(DB::table('cms_policy_releases')->count())->toBe(2)->and((bool) $second->acceptance_required)->toBeFalse()->and($second->effective_at)->toBe('2026-12-02 06:00:30.000000')
            ->and((bool) $saved->fresh()->schedule_requires_acceptance)->toBeTrue();

        app(PublishDuePages::class)->handle();   // a replay adds nothing
        expect(DB::table('cms_policy_releases')->count())->toBe(2);
    });

    it('lets only people who manage policies call a release a minor edit', function () {
        ($this->release)('terms', 'T1');
        $page = Page::query()->where('kind', 'terms')->firstOrFail();
        $editor = catalogStaff(['cms.view', 'cms.edit', 'cms.publish']);
        $go = fn ($who, int $n) => $this->actingAs($who, 'admin')->post("/admin/cms/pages/{$page->public_id}/transition", ['action' => 'publish', 'version' => $n, 'acceptance_required' => '0'])->assertRedirect();

        $saved = app(SavePage::class)->handle($this->actor, $page, ['en' => ['title' => 'T2', 'slug' => 'terms', 'body' => '<p>t2</p>']], 1);
        $go($editor, 2);
        app(SavePage::class)->handle($this->actor, $saved->fresh(), ['en' => ['title' => 'T3', 'slug' => 'terms', 'body' => '<p>t3</p>']], 2);
        $go($this->manager, 3);

        expect(DB::table('cms_policy_releases')->orderBy('id')->pluck('acceptance_required')->map(fn ($v) => (bool) $v)->all())->toBe([true, true, false]);
    });
});

describe('which page is which policy', function () {
    it('allows one page per kind, fixes the kind after a release, and needs the manage right', function () {
        $a = app(SavePage::class)->handle($this->actor, null, ['en' => ['title' => 'Terms A', 'body' => '<p>a</p>']]);
        $b = app(SavePage::class)->handle($this->actor, null, ['en' => ['title' => 'Terms B', 'body' => '<p>b</p>']]);

        $this->actingAs($this->manager, 'admin')->post('/admin/cms/policies/terms/assign', ['page' => $a->public_id])->assertRedirect();
        expect($a->fresh()->kind)->toBe('terms');
        $this->actingAs($this->manager, 'admin')->post('/admin/cms/policies/terms/assign', ['page' => $b->public_id])->assertSessionHasErrors('policies');
        expect($b->fresh()->kind)->toBeNull();

        $this->actingAs($this->manager, 'admin')->post('/admin/cms/policies/terms/assign', ['page' => ''])->assertRedirect();   // clearing is allowed before any release
        expect($a->fresh()->kind)->toBeNull();

        ($this->release)('privacy', 'Privacy');
        $privacy = Page::query()->where('kind', 'privacy')->firstOrFail();
        $this->actingAs($this->manager, 'admin')->post('/admin/cms/policies/terms/assign', ['page' => $privacy->public_id])->assertSessionHasErrors('policies');   // released as privacy: fixed
        $this->actingAs($this->manager, 'admin')->post('/admin/cms/policies/privacy/assign', ['page' => ''])->assertSessionHasErrors('policies');
        expect($privacy->fresh()->kind)->toBe('privacy');

        $this->actingAs(catalogStaff(['cms.view', 'cms.edit', 'cms.publish']), 'admin')->post('/admin/cms/policies/terms/assign', ['page' => $b->public_id])->assertForbidden();
        $this->actingAs($this->manager, 'admin')->post('/admin/cms/policies/nonsense/assign', ['page' => $b->public_id])->assertClientError();
        expect($b->fresh()->kind)->toBeNull();
        $this->actingAs($this->manager, 'admin')->delete("/admin/cms/pages/{$privacy->public_id}")->assertStatus(422);   // live: unpublish first
        app(TransitionPage::class)->unpublish($this->actor, $privacy->fresh());
        $this->actingAs($this->manager, 'admin')->delete("/admin/cms/pages/{$privacy->public_id}")->assertStatus(422);   // unpublished, but still a policy with history
        expect(Page::query()->whereKey($privacy->id)->exists())->toBeTrue();
    });
});

describe('what a person still has to accept', function () {
    it('asks for nothing until a policy has been published', function () {
        expect(($this->open)(null))->toBe([])->and(($this->open)(null, 'checkout', 'cod'))->toBe([]);
    });

    it('asks for terms and privacy at sign-up, and also the cash-on-delivery policy at checkout only when paying cash', function () {
        foreach (['terms', 'privacy', 'returns', 'shipping', 'cod'] as $kind) {
            ($this->release)($kind);
        }

        expect(($this->open)(null))->toBe(['terms', 'privacy'])                       // returns and shipping are links only
            ->and(($this->open)(null, 'checkout', 'bkash'))->toBe(['terms', 'privacy'])
            ->and(($this->open)(null, 'checkout', 'cod'))->toBe(['terms', 'privacy', 'cod'])
            ->and(($this->open)(null, 'checkout'))->toBe(['terms', 'privacy']);
    });

    it('stops asking once accepted, asks again for a new required release, and not for a minor edit', function () {
        ($this->release)('terms');
        ($this->release)('privacy');
        $u = ($this->customer)()->id;
        expect(($this->open)($u))->toBe(['terms', 'privacy']);

        ($this->policies)()->accept($u, null, 'signup', ($this->ids)($u));
        expect(($this->open)($u))->toBe([]);

        ($this->release)('terms', 'Terms new', false);   // minor edit
        expect(($this->open)($u))->toBe([]);

        ($this->release)('terms', 'Terms big change', true);
        expect(($this->open)($u))->toBe(['terms']);

        ($this->release)('terms', 'Terms tidy-up', false);   // a minor edit after the big one: still has to accept the big one
        expect(($this->open)($u))->toBe(['terms']);

        ($this->policies)()->accept($u, null, 'account', ($this->ids)($u));
        expect(($this->open)($u))->toBe([])->and(DB::table('policy_acceptances')->where('user_id', $u)->count())->toBe(3);
    });

    it('asks again when a required rollback is released, and always asks a guest', function () {
        ($this->release)('terms', 'T1');
        $u = ($this->customer)()->id;
        ($this->policies)()->accept($u, null, 'signup', ($this->ids)($u));
        $page = Page::query()->where('kind', 'terms')->firstOrFail();

        $saved = app(SavePage::class)->handle($this->actor, $page, ['en' => ['title' => 'T2', 'slug' => 'terms', 'body' => '<p>t2</p>']], 1);
        app(TransitionPage::class)->publish($this->actor, $saved, 2, false);
        expect(($this->open)($u))->toBe([]);

        app(TransitionPage::class)->publish($this->actor, $saved->fresh(), 1, true);   // back to the old text, as a release that asks
        expect(($this->open)($u))->toBe(['terms'])->and(($this->open)(null))->toBe(['terms']);
    });

    it('ignores a release that has not taken effect yet', function () {
        ($this->release)('terms');
        $u = ($this->customer)()->id;
        ($this->policies)()->accept($u, null, 'signup', ($this->ids)($u));
        $first = DB::table('cms_policy_releases')->first();
        DB::table('cms_policy_releases')->insert(['page_id' => $first->page_id, 'version_id' => $first->version_id, 'kind' => 'terms', 'effective_at' => '2026-12-05 00:00:00', 'acceptance_required' => true, 'created_at' => now()]);

        $future = (int) DB::table('cms_policy_releases')->max('id');

        expect(($this->open)($u))->toBe([])
            ->and(($this->ids)(($this->customer)('new@example.com')->id))->toBe([(int) $first->id])                    // a new person is shown the policy in force, not the one still to come
            ->and(fn () => ($this->policies)()->accept($u, null, 'account', [$future]))->toThrow(PolicyException::class, 'policies_changed');
        ($this->at)('2026-12-05 00:00:01');
        expect(($this->open)($u))->toBe(['terms'])->and(($this->ids)($u))->toBe([$future]);
    });

    it('describes each notice in the person\'s language, with the version and a link only while the page is live', function () {
        ($this->release)('terms', 'Terms');
        $en = ($this->policies)()->outstanding(null, 'signup', null, 'en')[0];
        $bn = ($this->policies)()->outstanding(null, 'signup', null, 'bn')[0];

        expect($en->title)->toBe('Terms')->and($en->versionNumber)->toBe(1)->and($en->url)->toBe(url('/en/pages/terms'))
            ->and($bn->title)->toBe('Terms বাংলা')
            ->and($bn->url)->toBe(url('/bn/pages/'.rawurlencode((string) DB::table('cms_page_translations')->where('locale', 'bn')->value('slug'))));

        app(TransitionPage::class)->unpublish($this->actor, Page::query()->where('kind', 'terms')->firstOrFail());
        expect(($this->policies)()->outstanding(null, 'signup', null, 'en')[0]->url)->toBeNull();   // still in force, but nothing to link to
    });
});

describe('recording acceptance', function () {
    it('records the current release once per customer, and once per guest order', function () {
        ($this->release)('terms');
        ($this->release)('privacy');
        $u = ($this->customer)()->id;
        fakeOrders([501]);
        $ids = ($this->ids)($u);

        ($this->policies)()->accept($u, null, 'signup', $ids);
        ($this->policies)()->accept($u, null, 'signup', $ids);                 // a double click
        ($this->policies)()->accept(null, 501, 'checkout', $ids);
        ($this->policies)()->accept(null, 501, 'checkout', array_reverse($ids));   // the same again, ids in another order

        expect(($this->open)(null))->toBe(['terms', 'privacy']);   // another guest's order having accepted does not excuse the next guest
        expect(DB::table('policy_acceptances')->where('user_id', $u)->count())->toBe(2)->and(DB::table('policy_acceptances')->where('order_id', 501)->count())->toBe(2)
            ->and(DB::table('policy_acceptances')->where('order_id', 501)->whereNotNull('user_id')->count())->toBe(0);
    });

    it('refuses a release that is no longer the current one, and records nothing at all when any one is stale', function () {
        $oldTerms = ($this->release)('terms', 'T1');
        $privacy = ($this->release)('privacy');
        ($this->release)('terms', 'T2');   // terms moved on
        $u = ($this->customer)()->id;

        expect(fn () => ($this->policies)()->accept($u, null, 'signup', [$oldTerms, $privacy]))->toThrow(PolicyException::class, 'policies_changed')
            ->and(fn () => ($this->policies)()->accept($u, null, 'signup', [999999]))->toThrow(PolicyException::class, 'policies_changed')
            ->and(fn () => ($this->policies)()->accept($u, null, 'signup', []))->toThrow(PolicyException::class, 'policies_required')
            ->and(DB::table('policy_acceptances')->count())->toBe(0);
    });

    it('is accepted by a customer or by an order, never both and never neither', function () {
        $id = ($this->release)('terms');
        fakeOrders([502]);
        $u = ($this->customer)()->id;

        expect(fn () => ($this->policies)()->accept($u, 502, 'checkout', [$id]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => ($this->policies)()->accept(null, null, 'checkout', [$id]))->toThrow(InvalidArgumentException::class)
            ->and(DB::table('policy_acceptances')->count())->toBe(0);
    });

    it('checks that a form carried exactly what is outstanding', function () {
        ($this->release)('terms');
        ($this->release)('privacy');
        $ids = ($this->ids)(null);
        $policies = ($this->policies)();

        expect($policies->assertShown(null, 'signup', null, array_reverse($ids)))->toBeNull()                       // order does not matter
            ->and(fn () => $policies->assertShown(null, 'signup', null, []))->toThrow(PolicyException::class, 'policies_required')
            ->and(fn () => $policies->assertShown(null, 'signup', null, [$ids[0]]))->toThrow(PolicyException::class, 'policies_changed')
            ->and(fn () => $policies->assertShown(null, 'signup', null, [...$ids, 999]))->toThrow(PolicyException::class, 'policies_changed')
            ->and(fn () => $policies->assertShown(null, 'signup', null, ['x']))->toThrow(PolicyException::class);
    });

    it('lets the database refuse anything that rewrites or removes an acceptance', function () {
        $id = ($this->release)('terms');
        $u = ($this->customer)()->id;
        fakeOrders([503]);
        ($this->policies)()->accept($u, null, 'signup', [$id]);
        $row = DB::table('policy_acceptances')->first();

        expect(fn () => DB::table('policy_acceptances')->where('id', $row->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('policy_acceptances')->where('id', $row->id)->update(['channel' => 'checkout']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('policy_acceptances')->where('id', $row->id)->update(['accepted_at' => now()]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('policy_acceptances')->where('id', $row->id)->update(['user_id' => null]))->toThrow(QueryException::class)                  // unlinking without erasing
            ->and(fn () => DB::table('policy_acceptances')->where('id', $row->id)->update(['order_id' => 503]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('policy_acceptances')->insert(['release_id' => $id, 'user_id' => $u, 'order_id' => 503, 'channel' => 'signup', 'accepted_at' => now()]))->toThrow(QueryException::class)    // both
            ->and(fn () => DB::table('policy_acceptances')->insert(['release_id' => $id, 'channel' => 'signup', 'accepted_at' => now()]))->toThrow(QueryException::class)                                  // neither
            ->and(fn () => DB::table('policy_acceptances')->insert(['release_id' => $id, 'order_id' => 503, 'channel' => 'sms', 'accepted_at' => now()]))->toThrow(QueryException::class)                  // channel
            ->and(fn () => DB::table('cms_policy_releases')->where('id', $id)->update(['acceptance_required' => false]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('cms_pages')->where('kind', 'terms')->update(['kind' => 'bogus']))->toThrow(QueryException::class);
        DB::table('policy_acceptances')->where('id', $row->id)->update(['user_id' => null, 'erased_at' => now()]);   // the one allowed change: erasure
        expect(DB::table('policy_acceptances')->where('id', $row->id)->value('user_id'))->toBeNull();
    });

    it('keeps no address, phone or device with an acceptance', function () {
        expect(Schema::getColumnListing('policy_acceptances'))->toBe(['id', 'release_id', 'user_id', 'order_id', 'channel', 'accepted_at', 'erased_at']);
    });
});

describe('sign-up with email', function () {
    beforeEach(function () {
        $this->form = fn (array $over = []) => array_merge(['name' => 'Amina Rahman', 'email' => 'amina@example.com', 'password' => 'Tr0ub4dor-and-3-horses', 'password_confirmation' => 'Tr0ub4dor-and-3-horses'], $over);
    });

    it('is as before while nothing is published', function () {
        $this->get('/en/register')->assertOk()->assertDontSee('accept_policies', false);
        $this->post('/en/register', ($this->form)())->assertRedirect('/en/register/check-your-email');
        expect(DB::table('users')->where('email', 'amina@example.com')->exists())->toBeTrue()->and(DB::table('policy_acceptances')->count())->toBe(0);
    });

    it('shows the box with links in both languages and needs it ticked', function () {
        ($this->release)('terms', 'Terms');
        ($this->release)('privacy', 'Privacy');

        $this->get('/en/register')->assertOk()->assertSee('name="accept_policies"', false)->assertSee('I have read and accept the')->assertSee('href="'.url('/en/pages/terms').'"', false);
        $this->get('/bn/register')->assertOk()->assertSee('আমি পড়েছি এবং মেনে নিচ্ছি:')->assertSee('Terms বাংলা');

        $this->from('/en/register')->post('/en/register', ($this->form)())->assertSessionHasErrors('accept_policies');
        $this->from('/en/register')->post('/en/register', ($this->form)(['accept_policies' => '1']))->assertSessionHasErrors('policy_releases');
        expect(DB::table('users')->where('email', 'amina@example.com')->exists())->toBeFalse();
    });

    it('creates the account and records what was accepted, in one go', function () {
        ($this->release)('terms');
        ($this->release)('privacy');
        $this->post('/en/register', ($this->form)(['accept_policies' => '1', 'policy_releases' => ($this->ids)(null)]))->assertRedirect('/en/register/check-your-email');

        $user = User::query()->where('email', 'amina@example.com')->firstOrFail();
        expect(DB::table('policy_acceptances')->where('user_id', $user->id)->where('channel', 'signup')->count())->toBe(2)->and(($this->open)($user->id))->toBe([]);
    });

    it('creates nothing when a policy changed while the person was reading', function () {
        ($this->release)('terms');
        ($this->release)('privacy');
        $shown = ($this->ids)(null);
        ($this->release)('terms', 'Terms changed');

        $this->from('/en/register')->post('/en/register', ($this->form)(['accept_policies' => '1', 'policy_releases' => $shown]))->assertRedirect('/en/register')->assertSessionHasErrors('policies');
        expect(DB::table('users')->where('email', 'amina@example.com')->exists())->toBeFalse()->and(DB::table('policy_acceptances')->count())->toBe(0);
    });

    it('refuses a box that carries only some of the policies on offer', function () {
        ($this->release)('terms');
        ($this->release)('privacy');
        $ids = ($this->ids)(null);

        $this->from('/en/register')->post('/en/register', ($this->form)(['accept_policies' => '1', 'policy_releases' => [$ids[0]]]))->assertRedirect('/en/register')->assertSessionHasErrors('policies');
        expect(DB::table('users')->where('email', 'amina@example.com')->exists())->toBeFalse()->and(DB::table('policy_acceptances')->count())->toBe(0);
    });

    it('gives the same answer for an address that already has an account, and records nothing for it', function () {
        ($this->release)('terms');
        $existing = ($this->customer)();
        $before = DB::table('policy_acceptances')->count();

        $this->post('/en/register', ($this->form)(['accept_policies' => '1', 'policy_releases' => ($this->ids)(null)]))->assertRedirect('/en/register/check-your-email');
        expect(DB::table('policy_acceptances')->count())->toBe($before)->and(DB::table('users')->where('email', $existing->email)->count())->toBe(1);
    });
});

describe('sign-in with a phone code', function () {
    beforeEach(function () {
        $this->sms = app(FakeSmsGateway::class);
        $this->phone = fn (array $over = []) => $this->post('/en/login/phone', array_merge(['phone' => '01712345678'], $over));
    });

    it('needs the box once a policy is published, and records it when this first sign-in makes the account', function () {
        ($this->release)('terms');
        ($this->release)('privacy');

        $this->get('/en/login/phone')->assertOk()->assertSee('name="accept_policies"', false);
        ($this->phone)()->assertSessionHasErrors('accept_policies');

        ($this->phone)(['accept_policies' => '1', 'policy_releases' => ($this->ids)(null)])->assertRedirect('/en/login/phone/verify');
        $this->post('/en/login/phone/verify', ['code' => $this->sms->lastCode()])->assertRedirect('/en/account');

        $user = User::query()->where('phone', '+8801712345678')->firstOrFail();
        expect(DB::table('policy_acceptances')->where('user_id', $user->id)->where('channel', 'signup')->count())->toBe(2);
    });

    it('does not record again for someone who already has an account', function () {
        ($this->release)('terms');
        $user = User::factory()->phoneOnly()->create();
        ($this->policies)()->accept($user->id, null, 'signup', ($this->ids)($user->id));
        $before = DB::table('policy_acceptances')->get()->all();

        ($this->phone)(['accept_policies' => '1', 'policy_releases' => ($this->ids)(null)])->assertRedirect('/en/login/phone/verify');
        $this->post('/en/login/phone/verify', ['code' => $this->sms->lastCode()])->assertRedirect('/en/account');

        expect(DB::table('policy_acceptances')->get()->all())->toEqual($before);
    });

    it('still signs in when a policy changes between the phone page and the code, and asks again from the account page', function () {
        ($this->release)('terms');
        ($this->phone)(['accept_policies' => '1', 'policy_releases' => ($this->ids)(null)])->assertRedirect('/en/login/phone/verify');
        ($this->release)('terms', 'Terms changed');

        $this->post('/en/login/phone/verify', ['code' => $this->sms->lastCode()])->assertRedirect('/en/account');
        $user = User::query()->where('phone', '+8801712345678')->firstOrFail();
        expect(DB::table('policy_acceptances')->where('user_id', $user->id)->count())->toBe(0)->and(($this->open)($user->id))->toBe(['terms']);
    });
});

describe('the account area', function () {
    it('shows a banner and the policies page only to a customer with something outstanding', function () {
        ($this->release)('terms', 'Terms');
        $u = ($this->customer)();

        $this->actingAs($u, 'web')->get('/en/account')->assertOk()->assertSee('We updated our policies. Please read and accept them.');
        $this->actingAs($u, 'web')->get('/en/account/policies')->assertOk()->assertSee('Terms')->assertSee('version 1')->assertDontSee('We updated our policies');

        $this->actingAs($u, 'web')->post('/en/account/policies', ['accept_policies' => '1', 'policy_releases' => ($this->ids)($u->id)])->assertRedirect('/en/account/policies');
        $this->actingAs($u, 'web')->get('/en/account')->assertOk()->assertDontSee('We updated our policies');
        $this->actingAs($u, 'web')->get('/en/account/policies')->assertOk()->assertSee('You have accepted our current policies.');
        expect(DB::table('policy_acceptances')->where('user_id', $u->id)->value('channel'))->toBe('account');
    });

    it('works in Bangla', function () {
        ($this->release)('terms', 'Terms');
        $u = ($this->customer)();
        $this->actingAs($u, 'web')->get('/bn/account')->assertOk()->assertSee('আমরা নীতিমালা হালনাগাদ করেছি');
        $this->actingAs($u, 'web')->get('/bn/account/policies')->assertOk()->assertSee('সংস্করণ 1')->assertSee('মেনে নিচ্ছি');
    });

    it('sends guests to sign in and needs the box ticked', function () {
        ($this->release)('terms');
        $this->get('/en/account/policies')->assertRedirect();
        $this->post('/en/account/policies', ['accept_policies' => '1', 'policy_releases' => [1]])->assertRedirect();   // not signed in: sent to sign in, nothing recorded
        expect(DB::table('policy_acceptances')->count())->toBe(0);

        $u = ($this->customer)();
        $this->actingAs($u, 'web')->from('/en/account/policies')->post('/en/account/policies', ['policy_releases' => ($this->ids)($u->id)])->assertSessionHasErrors('accept_policies');
        expect(DB::table('policy_acceptances')->count())->toBe(0);
    });

    it('turns away a stale form and cannot be used to accept for someone else', function () {
        ($this->release)('terms');
        $a = ($this->customer)('a@example.com');
        $b = ($this->customer)('b@example.com');
        $shown = ($this->ids)($a->id);
        ($this->release)('terms', 'Terms changed');

        $this->actingAs($a, 'web')->post('/en/account/policies', ['accept_policies' => '1', 'policy_releases' => $shown])->assertSessionHasErrors('policies');
        expect(DB::table('policy_acceptances')->count())->toBe(0);

        $this->actingAs($a, 'web')->post('/en/account/policies', ['accept_policies' => '1', 'policy_releases' => ($this->ids)($a->id), 'user_id' => $b->id, 'user' => $b->public_id])->assertRedirect();
        expect(DB::table('policy_acceptances')->where('user_id', $a->id)->count())->toBe(1)->and(DB::table('policy_acceptances')->where('user_id', $b->id)->count())->toBe(0)
            ->and(($this->open)($b->id))->toBe(['terms']);
    });

    it('costs the same number of queries however much history the customer has', function () {
        ($this->release)('terms');
        $u = ($this->customer)();
        ($this->policies)()->accept($u->id, null, 'signup', ($this->ids)($u->id));
        ($this->release)('terms', 'Terms newer');   // something is outstanding, so the page has to describe it
        $count = function () use ($u) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($u, 'web')->get('/en/account')->assertOk()->assertSee('We updated our policies');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count();
        $few = $count();
        foreach (range(1, 12) as $i) {
            ($this->policies)()->accept($u->id, null, 'account', ($this->ids)($u->id));
            ($this->release)('terms', "Terms {$i}");
        }
        $count();

        expect($count())->toBe($few);
    });
});

describe('a customer\'s data rights', function () {
    it('puts a customer\'s own acceptances in their data copy and nobody else\'s', function () {
        ($this->release)('terms', 'Terms');
        $a = ($this->customer)('a@example.com');
        $b = ($this->customer)('b@example.com');
        ($this->policies)()->accept($a->id, null, 'signup', ($this->ids)($a->id));
        ($this->policies)()->accept($b->id, null, 'account', ($this->ids)($b->id));

        $copy = app(PolicyPersonalData::class)->export($a->id);
        expect($copy)->toHaveCount(1)->and($copy[0])->toMatchArray(['policy' => 'terms', 'title' => 'Terms', 'version' => 1, 'channel' => 'signup'])
            ->and(json_encode($copy))->not->toContain((string) $b->id);
        expect(collect(app(PersonalDataCatalog::class)->exporters())->map(fn ($e) => $e->section())->all())->toContain('policy_acceptances')
            ->and(collect(app(PersonalDataCatalog::class)->erasers())->map(fn ($e) => $e->section())->all())->toContain('policy_acceptances');
    });

    it('makes acceptances anonymous on erasure, keeps the counts, touches nobody else and can run twice', function () {
        ($this->release)('terms');
        $a = ($this->customer)('a@example.com');
        $b = ($this->customer)('b@example.com');
        fakeOrders([601]);
        foreach ([$a, $b] as $u) {
            ($this->policies)()->accept($u->id, null, 'signup', ($this->ids)($u->id));
        }
        ($this->policies)()->accept(null, 601, 'checkout', ($this->ids)(null));
        $releaseId = (int) DB::table('cms_policy_releases')->value('id');
        $bRow = DB::table('policy_acceptances')->where('user_id', $b->id)->first();

        $eraser = app(PolicyPersonalData::class);
        $eraser->erase($a->id);
        $eraser->erase($a->id);

        expect(DB::table('policy_acceptances')->where('release_id', $releaseId)->count())->toBe(3)                                       // the count survives
            ->and(DB::table('policy_acceptances')->where('user_id', $a->id)->count())->toBe(0)
            ->and(DB::table('policy_acceptances')->whereNotNull('erased_at')->count())->toBe(1)
            ->and(DB::table('policy_acceptances')->where('id', $bRow->id)->first())->toEqual($bRow)                                         // Bob untouched
            ->and(DB::table('policy_acceptances')->where('order_id', 601)->whereNull('erased_at')->count())->toBe(1)                        // a guest order has no person in it
            ->and($eraser->export($a->id))->toBe([])->and($eraser->blockers($a->id))->toBe([]);
    });

    it('carries what a guest accepted at checkout over to the account made from that order', function () {
        ($this->release)('terms');
        ($this->release)('privacy');
        fakeOrders([602, 603]);
        ($this->policies)()->accept(null, 602, 'checkout', ($this->ids)(null));
        $u = ($this->customer)();

        ($this->policies)()->carryOrderToUser(602, $u->id);
        ($this->policies)()->carryOrderToUser(602, $u->id);    // twice is harmless
        ($this->policies)()->carryOrderToUser(603, $u->id);    // an order with nothing accepted adds nothing

        expect(DB::table('policy_acceptances')->where('user_id', $u->id)->count())->toBe(2)->and(($this->open)($u->id))->toBe([])
            ->and(DB::table('policy_acceptances')->where('order_id', 602)->count())->toBe(2);
    });
});

describe('the staff screen', function () {
    it('lists the five policies with the page, the version in force and the acceptances by channel', function () {
        ($this->release)('terms', 'Terms');
        $u = ($this->customer)();
        fakeOrders([701]);
        ($this->policies)()->accept($u->id, null, 'signup', ($this->ids)($u->id));
        ($this->policies)()->accept(null, 701, 'checkout', ($this->ids)(null));

        $this->actingAs($this->manager, 'admin')->get('/admin/cms/policies')->assertOk()
            ->assertSee('Terms and conditions')->assertSee('Privacy policy')->assertSee('Returns policy')->assertSee('Shipping policy')->assertSee('Cash on delivery policy')
            ->assertSee('Accepted this version: 2')->assertSee('Sign-up: 1')->assertSee('Checkout: 1')->assertSee('version 1')->assertSee('No page chosen yet.');

        $draft = app(SavePage::class)->handle($this->actor, null, ['en' => ['title' => 'Privacy draft', 'body' => '<p>x</p>']]);
        app(AssignPolicyKind::class)->handle($this->actor, $draft, PolicyKind::Privacy);
        $this->actingAs($this->manager, 'admin')->get('/admin/cms/policies')->assertOk()->assertSee('Privacy draft')->assertSee('Not published yet.');
    });

    it('shows counts only to those who may see them and the controls only to those who manage', function () {
        ($this->release)('terms', 'Terms');
        $u = ($this->customer)();
        ($this->policies)()->accept($u->id, null, 'signup', ($this->ids)($u->id));

        $viewer = catalogStaff(['cms.view']);
        $this->actingAs($viewer, 'admin')->get('/admin/cms/policies')->assertOk()->assertDontSee('Accepted this version')->assertDontSee('Use this page')->assertDontSee('Change page');
        $this->actingAs(catalogStaff(['cms.view', 'policies.acceptance.view']), 'admin')->get('/admin/cms/policies')->assertOk()->assertSee('Accepted this version: 1')->assertDontSee('Change page');
        $this->actingAs($this->manager, 'admin')->get('/admin/cms/policies')->assertOk()->assertSee('Change page')->assertSee('Use this page');
        $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/cms/policies')->assertForbidden();
    });

    it('reads in Bangla and keeps the two language files in step', function () {
        $this->manager->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($this->manager->fresh(), 'admin')->get('/admin/cms/policies')->assertOk()->assertSee('শর্তাবলী')->assertSee('গোপনীয়তা নীতি');

        $keys = function (array $a, string $prefix = '') use (&$keys): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? array_push($out, ...$keys($v, "{$prefix}{$k}.")) : $out[] = "{$prefix}{$k}";
            }

            return $out;
        };
        $en = $keys(require app_path('Modules/Cms/resources/lang/en/policies.php'));
        $bn = $keys(require app_path('Modules/Cms/resources/lang/bn/policies.php'));
        expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
    });

    it('seeds placeholder policy pages that nobody is asked to accept until they are published', function () {
        $this->seed(DemoCmsSeeder::class);

        expect(Page::query()->whereNotNull('kind')->count())->toBe(5)->and(Page::query()->whereNotNull('kind')->where('status', 'published')->count())->toBe(0)
            ->and(DB::table('cms_policy_releases')->count())->toBe(0)->and(($this->open)(null))->toBe([]);
    });
});
