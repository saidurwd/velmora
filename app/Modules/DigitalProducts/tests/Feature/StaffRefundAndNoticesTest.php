<?php

declare(strict_types=1);

use App\Modules\DigitalProducts\Actions\AdjustEntitlement;
use App\Modules\DigitalProducts\Actions\ImportLicenseKeys;
use App\Modules\DigitalProducts\Events\DigitalAssetVersionPublished;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Listeners\NotifyOwnersOfNewVersion;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\DigitalProducts\Models\RefundPolicy;
use App\Modules\DigitalProducts\Services\RefundPolicies;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Actions\DeliverDueNotifications;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Outbox\OutboxDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    digitalWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    $this->manager = catalogStaff(['digital.view', 'digital.manage']);
    $this->viewer = catalogStaff(['digital.view']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->staff = Actor::system('t');
    $this->buy = function (string $sku = 'BOOK-1', ?string $email = null, bool $guest = false, string $locale = 'en') {
        $order = ($this->place)([($sku === 'BOOK-1' ? $this->book : $this->book2) => 1], $guest ? null : $this->owner->id, '+8801712345678', $email, $locale);
        ($this->pay)($order->id);

        return [$order, DB::table('entitlements')->where('order_id', $order->id)->first()];
    };
    $this->right = fn (int $id) => Entitlement::query()->findOrFail($id);
    $this->words = fn (string $title = 'My policy', string $text = 'Some words.') => ['en' => ['title' => $title, 'text' => $text], 'bn' => ['title' => '', 'text' => '']];
    $this->form = fn (array $over = []) => array_replace(['rule' => 'until_first_use', 'revoke' => '1', 'words' => ($this->words)()], $over);
});

describe('the refund policy editor', function () {
    it('lists the policies to anyone who can see digital files, and lets only a manager change them', function () {
        $policy = RefundPolicy::query()->where('is_default', true)->firstOrFail();

        $this->get('/admin/digital/policies')->assertRedirect();
        $this->actingAs($this->nobody, 'admin')->get('/admin/digital/policies')->assertForbidden();
        $this->actingAs($this->viewer, 'admin')->get('/admin/digital/policies')->assertOk()->assertSee('Refunds for digital products')->assertSee('Default')->assertDontSee('New policy');
        $this->actingAs($this->viewer, 'admin')->get("/admin/digital/policies/{$policy->public_id}/edit")->assertOk()->assertDontSee('Save policy');
        $this->actingAs($this->viewer, 'admin')->get('/admin/digital/policies/create')->assertForbidden();
        $this->actingAs($this->viewer, 'admin')->put("/admin/digital/policies/{$policy->public_id}", ($this->form)())->assertForbidden();
        $this->actingAs($this->viewer, 'admin')->post('/admin/digital/policies', ($this->form)())->assertForbidden();
        $this->actingAs($this->manager, 'admin')->get('/admin/digital/policies')->assertOk()->assertSee('New policy');
    });

    it('creates and changes a policy, with the words per language, and records it', function () {
        $this->actingAs($this->manager, 'admin')->post('/admin/digital/policies', ($this->form)(['rule' => 'within_days', 'days' => '14', 'words' => ['en' => ['title' => 'Two weeks', 'text' => 'Fourteen days.'], 'bn' => ['title' => 'দুই সপ্তাহ', 'text' => 'চৌদ্দ দিন।']]]))->assertSessionHas('status', 'The policy was saved.');

        $policy = RefundPolicy::query()->where('rule', 'within_days')->with('translations')->firstOrFail();
        expect($policy->days)->toBe(14)->and($policy->is_default)->toBeFalse()->and($policy->translations)->toHaveCount(2)->and($policy->words('bn')['title'])->toBe('দুই সপ্তাহ');

        $this->actingAs($this->manager, 'admin')->put("/admin/digital/policies/{$policy->public_id}", ($this->form)(['rule' => 'non_refundable', 'revoke' => '0', 'words' => ['en' => ['title' => 'Final sale', 'text' => 'No refunds.'], 'bn' => ['title' => '', 'text' => '']]]))->assertSessionHas('status');
        $policy->refresh()->load('translations');
        expect($policy->rule)->toBe('non_refundable')->and($policy->days)->toBeNull()->and($policy->revoke_on_full_refund)->toBeFalse()->and($policy->translations)->toHaveCount(1)->and($policy->words('bn')['title'])->toBe('Final sale');   // no Bangla words: English
        expect(DB::table('audit_events')->whereIn('action', ['digital.refund_policy_created', 'digital.refund_policy_changed'])->count())->toBe(2);
    });

    it('refuses what makes no sense: no English words, half a translation, a time rule without days, an unknown rule', function () {
        $post = fn (array $over) => $this->actingAs($this->manager, 'admin')->post('/admin/digital/policies', ($this->form)($over));

        $post(['words' => ['en' => ['title' => '', 'text' => '']]])->assertSessionHasErrors(['policy' => 'The policy needs a title and a text in English.']);
        $post(['words' => ['en' => ['title' => 'T', 'text' => 'X'], 'bn' => ['title' => 'শুধু শিরোনাম', 'text' => '']]])->assertSessionHasErrors('policy');
        $post(['rule' => 'within_days', 'days' => ''])->assertSessionHasErrors(['policy' => 'Enter a number of days from 1 to 3650.']);
        $post(['rule' => 'within_days', 'days' => '99999'])->assertSessionHasErrors('days');
        $post(['rule' => 'whatever'])->assertSessionHasErrors('rule');
        expect(RefundPolicy::query()->count())->toBe(1);
    });

    it('has at most one default, and making another the default moves it', function () {
        $this->actingAs($this->manager, 'admin')->post('/admin/digital/policies', ($this->form)(['is_default' => '1']))->assertSessionHas('status');

        $defaults = RefundPolicy::query()->where('is_default', true)->get();
        expect($defaults)->toHaveCount(1)->and($defaults[0]->code)->not->toBe('default')->and(fn () => DB::table('refund_policies')->where('code', 'default')->update(['is_default' => true]))->toThrow(QueryException::class);
    });

    it('lets a manager pick a policy for one product, or hand it back to the default', function () {
        $own = RefundPolicy::query()->create(['public_id' => strtolower((string) Str::ulid()), 'code' => 'own', 'rule' => 'non_refundable', 'revoke_on_full_refund' => true, 'is_default' => false]);
        DB::table('refund_policy_translations')->insert(['policy_id' => $own->id, 'locale' => 'en', 'title' => 'Final sale', 'text' => 'No refunds.']);
        $page = '/admin/digital/assets/BOOK-1';

        $this->actingAs($this->viewer, 'admin')->post("{$page}/policy", ['policy' => $own->public_id])->assertForbidden();
        $this->actingAs($this->manager, 'admin')->get($page)->assertOk()->assertSee('Refund policy of this product')->assertSee('Final sale');
        $this->actingAs($this->manager, 'admin')->post("{$page}/policy", ['policy' => $own->public_id])->assertSessionHas('status');
        expect(app(RefundPolicies::class)->forVariant($this->book)->code)->toBe('own');
        $this->actingAs($this->manager, 'admin')->post("{$page}/policy", ['policy' => ''])->assertSessionHas('status');
        expect(app(RefundPolicies::class)->forVariant($this->book)->code)->toBe('default');
        $this->actingAs($this->manager, 'admin')->post("{$page}/policy", ['policy' => '01zzzzzzzzzzzzzzzzzzzzzzzz'])->assertSessionHasErrors(['asset' => 'That refund policy was not found.']);
        $this->actingAs($this->manager, 'admin')->post('/admin/digital/assets/TEE-1/policy', ['policy' => ''])->assertSessionHasErrors('asset');
    });
});

describe('a customer\'s downloads, for staff', function () {
    it('are found by order number, with what was used and what the policy says', function () {
        [$order, $right] = ($this->buy)();
        $page = fn (?string $q = null) => '/admin/digital/entitlements'.($q === null ? '' : '?order='.urlencode($q));

        $this->get($page())->assertRedirect();
        $this->actingAs($this->nobody, 'admin')->get($page())->assertForbidden();
        $this->actingAs($this->viewer, 'admin')->get($page($order->order_number))->assertOk()->assertSee('Cooking e-book')->assertSee('0 / 5')->assertSee('Not yet')->assertSee('Refundable')->assertSee('not been downloaded or looked at yet')
            ->assertSee('decided in Payments')->assertDontSee('Take away');
        $this->actingAs($this->viewer, 'admin')->get($page('VM-0000-999999'))->assertOk()->assertSee('No order has that number.');
        $this->actingAs($this->viewer, 'admin')->get($page(strtolower($order->order_number)))->assertOk()->assertSee('Cooking e-book');   // as printed, any case

        DB::table('entitlements')->where('id', $right->id)->update(['first_used_at' => now(), 'downloads_used' => 2]);
        $this->actingAs($this->viewer, 'admin')->get($page($order->order_number))->assertOk()->assertSee('2 / 5')->assertSee('Not refundable')->assertSee('already been downloaded');
    });

    it('can be given more time or more downloads, or counted again, and every change is recorded with before and after', function () {
        [, $right] = ($this->buy)();
        DB::table('entitlements')->where('id', $right->id)->update(['downloads_used' => 5, 'expires_at' => now()->subDay()]);
        $adjust = fn (array $data) => $this->actingAs($this->manager, 'admin')->post("/admin/digital/entitlements/{$right->public_id}/adjust", $data);

        $adjust(['action' => 'extend', 'amount' => '10'])->assertSessionHas('status', 'The change was made and recorded.');
        $adjust(['action' => 'add_downloads', 'amount' => '3'])->assertSessionHas('status');
        $row = ($this->right)($right->id);
        expect($row->expires_at->diffInDays(now()->addDays(10), true))->toBeLessThan(1)   // counted from today, not from last week
            ->and($row->download_limit)->toBe(8);
        $adjust(['action' => 'reset'])->assertSessionHas('status');
        expect(($this->right)($right->id)->downloads_used)->toBe(0)->and(DB::table('audit_events')->whereIn('action', ['digital.entitlement_extended', 'digital.entitlement_downloads_added', 'digital.entitlement_downloads_reset'])->count())->toBe(3);
        $audit = json_decode((string) DB::table('audit_events')->where('action', 'digital.entitlement_downloads_added')->value('before'), true);
        expect($audit['download_limit'])->toBe(5);
    });

    it('refuses nonsense and a right that cannot be changed', function () {
        [, $right] = ($this->buy)();
        $adjust = fn (array $data, $id = null) => $this->actingAs($this->manager, 'admin')->post('/admin/digital/entitlements/'.($id ?? $right->public_id).'/adjust', $data);

        $adjust(['action' => 'extend', 'amount' => '10'])->assertSessionHasErrors(['entitlement' => 'This download has no end date, so there is nothing to extend.']);
        $adjust(['action' => 'extend', 'amount' => '0'])->assertSessionHasErrors('amount');
        $adjust(['action' => 'fly', 'amount' => '1'])->assertSessionHasErrors('action');
        DB::table('entitlements')->where('id', $right->id)->update(['download_limit' => null]);
        $adjust(['action' => 'add_downloads', 'amount' => '2'])->assertSessionHasErrors(['entitlement' => 'This download has no limit, so there is nothing to add.']);
        $adjust(['action' => 'reset'], '01zzzzzzzzzzzzzzzzzzzzzzzz')->assertSessionHasErrors(['entitlement' => 'That download was not found.']);

        DB::table('entitlements')->where('id', $right->id)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $adjust(['action' => 'reset'])->assertSessionHasErrors(['entitlement' => 'This download has already been taken away.']);   // a revoked right is never touched
    });

    it('can be taken away by a manager with a reason, along with its keys, and never by a viewer', function () {
        app(ImportLicenseKeys::class)->handle($this->staff, 'BOOK-1', 'K-1');
        [, $right] = ($this->buy)();
        $url = "/admin/digital/entitlements/{$right->public_id}/revoke";

        $this->actingAs($this->viewer, 'admin')->post($url, ['reason' => 'Fraud'])->assertForbidden();
        $this->actingAs($this->manager, 'admin')->post($url, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager, 'admin')->post($url, ['reason' => 'Chargeback'])->assertSessionHas('status', 'The download was taken away.');
        $this->actingAs($this->manager, 'admin')->post($url, ['reason' => 'again'])->assertSessionHasErrors(['entitlement' => 'This download has already been taken away.']);

        expect(($this->right)($right->id)->revoked_reason)->toBe('Chargeback')->and(DB::table('license_keys')->value('status'))->toBe('revoked')->and(DB::table('audit_events')->where('action', 'digital.entitlement_revoked')->count())->toBe(1);
        $this->actingAs($this->manager, 'admin')->get('/admin/digital/entitlements?order='.DB::table('orders')->value('order_number'))->assertOk()->assertSee('Withdrawn')->assertSee('Chargeback');
    });

    it('cannot be changed for a different customer by editing the number in the address', function () {
        $this->actingAs($this->manager, 'admin')->post('/admin/digital/entitlements/nope/adjust', ['action' => 'reset'])->assertSessionHasErrors(['entitlement' => 'That download was not found.']);
        $this->actingAs($this->nobody, 'admin')->post('/admin/digital/entitlements/nope/adjust', ['action' => 'reset'])->assertForbidden();
        expect(fn () => app(AdjustEntitlement::class)->resetDownloads($this->staff, 'nope'))->toThrow(DigitalException::class);
    });
});

describe('telling owners about a new version', function () {
    it('sends each owner with an email one message when version 2 is put in, and nothing for the first version', function () {
        Mail::fake();
        ($this->buy)('BOOK-1', 'owner@example.com');
        ($this->buy)('BOOK-1', 'guest@example.com', true);
        ($this->buy)('BOOK-1');   // no email on this order
        ($this->buy)('BOOK-2', 'other@example.com');   // another product

        ($this->upload)('BOOK-1', 'v1.pdf');
        app(OutboxDispatcher::class)->dispatchDue();
        Mail::assertNothingSent();

        ($this->upload)('BOOK-1', 'v2.pdf');
        app(OutboxDispatcher::class)->dispatchDue();
        Mail::assertSent(NotificationMail::class, 2);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('owner@example.com') && $m->subjectLine === 'A new version of Cooking e-book is ready' && str_contains($m->text, 'Version 2 of what you bought') && str_contains($m->text, '/en/account/library'));
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('guest@example.com') && str_contains($m->text, '/downloads?') && str_contains($m->text, 'signature='));   // a guest gets a signed link
        Mail::assertNotSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('other@example.com'));
    });

    it('writes the email in the language the order was placed in, whatever language the staff member publishing the file uses', function () {
        Mail::fake();
        ($this->buy)('BOOK-1', 'bn@example.com', false, 'bn');
        ($this->upload)('BOOK-1', 'v1.pdf');
        ($this->upload)('BOOK-1', 'v2.pdf');
        app(OutboxDispatcher::class)->dispatchDue();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('bn@example.com') && str_contains($m->text, 'সংস্করণ') && ! str_contains($m->text, 'Version') && str_contains($m->text, '/bn/account/library'));
    });

    it('never tells the same owner twice about one version, even when the announcement is delivered again', function () {
        Mail::fake();
        ($this->buy)('BOOK-1', 'owner@example.com');
        ($this->upload)('BOOK-1', 'v1.pdf');
        $v2 = ($this->upload)('BOOK-1', 'v2.pdf');
        app(OutboxDispatcher::class)->dispatchDue();
        app(NotifyOwnersOfNewVersion::class)->handle(new DigitalAssetVersionPublished($v2->id, $this->book, 2));   // again, by hand

        Mail::assertSent(NotificationMail::class, 1);
        expect(DB::table('entitlement_version_notices')->count())->toBe(1)->and(DB::table('notification_logs')->where('template_code', 'digital.new_version.email')->count())->toBe(1);
        ($this->upload)('BOOK-1', 'v3.pdf');
        app(OutboxDispatcher::class)->dispatchDue();
        Mail::assertSent(NotificationMail::class, 2);   // a later version is news again
    });

    it('does not tell someone whose download was taken away', function () {
        Mail::fake();
        [, $right] = ($this->buy)('BOOK-1', 'owner@example.com');
        ($this->upload)('BOOK-1', 'v1.pdf');
        DB::table('entitlements')->where('id', $right->id)->update(['status' => 'revoked', 'revoked_at' => now()]);

        ($this->upload)('BOOK-1', 'v2.pdf');
        app(OutboxDispatcher::class)->dispatchDue();

        Mail::assertNothingSent();
    });

    it('keeps trying when the mail server is down, tells staff, and still tells nobody twice', function () {
        ($this->buy)('BOOK-1', 'one@example.com');
        ($this->buy)('BOOK-1', 'two@example.com');
        ($this->upload)('BOOK-1', 'v1.pdf');

        config(['mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 2], 'mail.default' => 'broken']);   // a mail server that is down
        Mail::purge();
        $v2 = ($this->upload)('BOOK-1', 'v2.pdf');   // the announcement is delivered straight after the upload; the emails cannot be sent
        $event = new DigitalAssetVersionPublished($v2->id, $this->book, 2);
        expect(DB::table('entitlement_version_notices')->count())->toBe(2)   // both owners were asked about once
            ->and(DB::table('notification_logs')->where('template_code', 'digital.new_version.email')->pluck('status')->all())->toBe(['failed', 'failed']);   // and staff can see that neither arrived

        config(['mail.default' => 'array']);
        Mail::purge();
        Mail::fake();
        app(NotifyOwnersOfNewVersion::class)->handle($event);   // the announcement again, by hand: nobody is asked twice
        $this->travel(2)->minutes();
        app(DeliverDueNotifications::class)->handle();   // what the scheduler does every minute
        app(DeliverDueNotifications::class)->handle();

        Mail::assertSent(NotificationMail::class, 2);   // each told once, after the retry
        expect(DB::table('entitlement_version_notices')->count())->toBe(2)->and(DB::table('notification_logs')->where('template_code', 'digital.new_version.email')->pluck('status')->all())->toBe(['sent', 'sent']);
    });

    it('shows a new version in the library at once, to owners who had no email', function () {
        [, $right] = ($this->buy)();
        ($this->upload)('BOOK-1', 'v1.pdf');
        ($this->upload)('BOOK-1', 'v2.pdf');

        $this->actingAs($this->owner)->get('/en/account/library')->assertOk()->assertSee('v2.pdf')->assertSee('version 2');
    });
});

describe('both languages', function () {
    it('show the new staff screens in Bangla with no missing text, and the email in the order\'s language', function () {
        [$order, $right] = ($this->buy)('BOOK-1', 'owner@example.com');
        $this->manager->forceFill(['locale' => 'bn'])->save();
        $policy = RefundPolicy::query()->where('is_default', true)->firstOrFail();

        foreach (['/admin/digital/policies', "/admin/digital/policies/{$policy->public_id}/edit", '/admin/digital/entitlements?order='.$order->order_number, '/admin/digital/assets/BOOK-1'] as $page) {
            expect($this->actingAs($this->manager, 'admin')->get($page)->assertOk()->getContent())->not->toContain('digital-products::');
        }
        $this->actingAs($this->manager, 'admin')->get('/admin/digital/policies')->assertSee('রিফান্ড নীতি');
        $this->owner->forceFill(['locale' => 'bn'])->save();
        $words = app(TemplateCatalog::class)->get('digital.new_version.email')->defaults['bn'];
        expect($words[1])->toContain('সংস্করণ {{ version }}')->not->toContain('digital-products::');   // the email goes out in Bangla for a Bangla order
    });
});
