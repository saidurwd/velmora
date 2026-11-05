<?php

declare(strict_types=1);

use App\Modules\Privacy\Actions\RequestErasure;
use App\Modules\Privacy\Exceptions\PrivacyException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    privacyWorld($this);
    $this->amina = ($this->makeCustomer)('Amina Rahman', 'amina@example.com', '+8801711111111');
    $this->bob = ($this->makeCustomer)('Bob Karim', 'bob@example.com', '+8801822222222');
    $this->ask = fn (?array $who = null, array $over = []) => ($this->as)(($who ?? $this->amina)['user'])->post('/en/account/privacy/erasure', $over + ['understand' => '1', 'reason' => '  I no longer shop here  ']);
    $this->req = fn (?array $who = null) => DB::table('privacy_requests')->where('user_id', (($who ?? $this->amina)['user'])->id)->where('type', 'erasure')->orderByDesc('id')->first();
    $this->staffPost = fn (string $action, object $request, array $data = [], ?object $as = null) => $this->actingAs($as ?? $this->manager, 'admin')->post("/admin/privacy/{$request->public_id}/{$action}", $data);
});

describe('asking to be erased', function () {
    it('is for signed-in customers who have just confirmed it is them', function () {
        $this->post('/en/account/privacy/erasure', ['understand' => '1'])->assertRedirect('/en/login');
        ($this->as)($this->amina['user'], confirmed: false)->post('/en/account/privacy/erasure', ['understand' => '1'])->assertRedirect('/en/account/confirm');

        expect(DB::table('privacy_requests')->count())->toBe(0);
    });

    it('makes a request and changes nothing else until staff decide', function () {
        ($this->ask)()->assertRedirect('/en/account/privacy')->assertSessionHas('status');

        $request = ($this->req)();
        expect($request->status)->toBe('requested')->and($request->reason)->toBe('I no longer shop here')->and($request->open_key)->toBe($this->amina['user']->id.':erasure')->and($request->locale)->toBe('en');
        $user = DB::table('users')->where('id', $this->amina['user']->id)->first();
        expect($user->email)->toBe('amina@example.com')->and($user->status)->toBe('active')
            ->and(DB::table('orders')->where('user_id', $user->id)->value('contact_name'))->toBe('Rahim');
    });

    it('needs the customer to say they understand', function () {
        ($this->ask)(null, ['understand' => ''])->assertSessionHasErrors('understand');

        expect(DB::table('privacy_requests')->count())->toBe(0);
    });

    it('allows one open request at a time', function () {
        ($this->ask)();
        ($this->ask)()->assertSessionHasErrors('privacy');

        expect(DB::table('privacy_requests')->where('type', 'erasure')->count())->toBe(1);
    });

    it('is never for a staff account', function () {
        expect(fn () => app(RequestErasure::class)->handle($this->manager->id, null, 'en'))->toThrow(PrivacyException::class);
    });

    it('can be withdrawn by the customer while it waits, and then asked again', function () {
        ($this->ask)();
        $request = ($this->req)();

        ($this->as)($this->amina['user'])->post("/en/account/privacy/erasure/{$request->public_id}/cancel")->assertRedirect('/en/account/privacy')->assertSessionHas('status');
        expect(DB::table('privacy_requests')->where('id', $request->id)->first())->status->toBe('cancelled')->open_key->toBeNull();

        ($this->ask)()->assertSessionHasNoErrors();
    });

    it('cannot be withdrawn by someone else, nor after staff approved it', function () {
        ($this->ask)();
        $request = ($this->req)();
        ($this->as)($this->bob['user'])->post("/en/account/privacy/erasure/{$request->public_id}/cancel")->assertNotFound();
        expect(DB::table('privacy_requests')->where('id', $request->id)->value('status'))->toBe('requested');

        DB::table('privacy_requests')->where('id', $request->id)->update(['status' => 'approved']);   // staff approved it a moment ago
        ($this->as)($this->amina['user'])->post("/en/account/privacy/erasure/{$request->public_id}/cancel")->assertSessionHasErrors('privacy');
        expect(DB::table('privacy_requests')->where('id', $request->id)->value('status'))->toBe('approved');
    });
});

describe('what staff see and may do', function () {
    it('is hidden from people without the permission, and from customers', function () {
        ($this->ask)();
        $request = ($this->req)();
        $nobody = catalogStaff(['catalog.view']);

        $this->get('/admin/privacy')->assertRedirect();
        $this->actingAs($this->amina['user'], 'web')->get('/admin/privacy')->assertRedirect();   // a customer's sign-in is not a staff sign-in
        $this->actingAs($nobody, 'admin')->get('/admin/privacy')->assertForbidden();
        $this->actingAs($nobody, 'admin')->get("/admin/privacy/{$request->public_id}")->assertForbidden();
    });

    it('lets a viewer look but not decide', function () {
        ($this->ask)();
        $request = ($this->req)();

        $this->actingAs($this->viewer, 'admin')->get('/admin/privacy')->assertOk()->assertSee('Amina Rahman');
        $this->actingAs($this->viewer, 'admin')->get("/admin/privacy/{$request->public_id}")->assertOk()->assertDontSee('Approve and erase');
        ($this->staffPost)('approve', $request, [], $this->viewer)->assertForbidden();
        ($this->staffPost)('reject', $request, ['reason' => 'x'], $this->viewer)->assertForbidden();

        expect(DB::table('privacy_requests')->where('id', $request->id)->value('status'))->toBe('requested');
    });

    it('shows what is still open and refuses to approve while an order is being handled', function () {
        ($this->ask)();
        $request = ($this->req)();   // the paid digital order is still "confirmed"

        $this->actingAs($this->manager, 'admin')->get("/admin/privacy/{$request->public_id}")->assertOk()->assertSee('An order is still being handled.');
        ($this->staffPost)('approve', $request)->assertSessionHasErrors('request');

        expect(DB::table('privacy_requests')->where('id', $request->id)->value('status'))->toBe('requested')
            ->and(DB::table('users')->where('id', $this->amina['user']->id)->value('email'))->toBe('amina@example.com');
    });

    it('also waits for an upcoming booking, and not for one that is over', function () {
        ($this->ask)();
        $request = ($this->req)();
        ($this->finish)($this->amina['order']->id);
        Schema::disableForeignKeyConstraints();
        DB::table('service_bookings')->insert(['public_id' => strtolower((string) Str::ulid()), 'package_id' => 1, 'resource_id' => 1, 'user_id' => $this->amina['user']->id, 'status' => 'confirmed',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'blocks_from' => now()->addDay(), 'blocks_until' => now()->addDay()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        Schema::enableForeignKeyConstraints();

        $this->actingAs($this->manager, 'admin')->get("/admin/privacy/{$request->public_id}")->assertSee('A booking is still to come.');
        ($this->staffPost)('approve', $request)->assertSessionHasErrors('request');
        expect(DB::table('privacy_requests')->where('id', $request->id)->value('status'))->toBe('requested');

        DB::table('service_bookings')->where('user_id', $this->amina['user']->id)->update(['status' => 'completed']);
        ($this->staffPost)('approve', $request)->assertSessionHasNoErrors();
        expect(DB::table('privacy_requests')->where('id', $request->id)->value('status'))->toBe('done');
    });

    it('approves once the orders are over, and the customer\'s account is erased', function () {
        ($this->ask)();
        $request = ($this->req)();
        ($this->finish)($this->amina['order']->id);

        ($this->staffPost)('approve', $request, ['reason' => 'checked with the customer'])->assertRedirect("/admin/privacy/{$request->public_id}")->assertSessionHas('status');

        $done = DB::table('privacy_requests')->where('id', $request->id)->first();
        expect($done->status)->toBe('done')->and($done->open_key)->toBeNull()->and($done->decided_by)->toBe($this->manager->id)->and($done->decision_reason)->toBe('checked with the customer')->and($done->completed_at)->not->toBeNull()->and($done->reason)->toBeNull();
        expect(DB::table('users')->where('id', $this->amina['user']->id)->value('email'))->toBe('erased-'.$this->amina['user']->public_id.'@erased.invalid');
        expect(DB::table('audit_events')->whereIn('action', ['privacy.erasure_approved', 'privacy.erased'])->count())->toBe(2);
    });

    it('decides only once, even if the page is submitted again', function () {
        ($this->ask)();
        $request = ($this->req)();
        ($this->finish)($this->amina['order']->id);

        ($this->staffPost)('approve', $request);
        ($this->staffPost)('approve', $request)->assertSessionHasErrors('request');
        ($this->staffPost)('reject', $request, ['reason' => 'too late'])->assertSessionHasErrors('request');

        expect(DB::table('privacy_requests')->where('id', $request->id)->value('status'))->toBe('done')->and(DB::table('audit_events')->where('action', 'privacy.erased')->count())->toBe(1);
    });

    it('rejects with a reason that the customer is told, and leaves the account as it was', function () {
        ($this->ask)();
        $request = ($this->req)();

        ($this->staffPost)('reject', $request, ['reason' => ''])->assertSessionHasErrors('reason');
        expect(DB::table('privacy_requests')->where('id', $request->id)->value('status'))->toBe('requested');

        ($this->staffPost)('reject', $request, ['reason' => 'We still have a warranty claim open.'])->assertSessionHas('status');
        $after = DB::table('privacy_requests')->where('id', $request->id)->first();
        expect($after->status)->toBe('rejected')->and($after->open_key)->toBeNull()->and($after->decision_reason)->toBe('We still have a warranty claim open.')
            ->and(DB::table('users')->where('id', $this->amina['user']->id)->value('email'))->toBe('amina@example.com');
        Mail::assertSent(Mailable::class, fn ($m) => $m->hasTo('amina@example.com') && str_contains($m->text, 'We still have a warranty claim open.'));

        $html = ($this->as)($this->amina['user'])->get('/en/account/privacy')->getContent();
        expect($html)->toContain('Not approved')->and($html)->toContain('We still have a warranty claim open.');
        ($this->ask)()->assertSessionHasNoErrors();   // may ask again
    });

    it('cannot decide a request that does not exist, or an export', function () {
        ($this->as)($this->amina['user'])->post('/en/account/privacy/export');
        $export = DB::table('privacy_requests')->where('type', 'export')->first();

        ($this->staffPost)('approve', $export)->assertSessionHasErrors('request');
        $this->actingAs($this->manager, 'admin')->post('/admin/privacy/'.str_repeat('0', 26).'/approve')->assertSessionHasErrors('request');
        $this->actingAs($this->manager, 'admin')->get('/admin/privacy/'.str_repeat('0', 26))->assertNotFound();
    });

    it('lists requests with filters and does not slow down as the list grows', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->manager, 'admin')->get('/admin/privacy')->assertOk();
            $log = array_map(fn ($q) => $q['query'], DB::getQueryLog());
            DB::disableQueryLog();
            $this->lastQueries = $log;

            return count($log);
        };
        ($this->ask)();
        $queries();   // the first request also loads the staff member's permissions; measure the second
        $few = $queries();
        foreach (range(1, 12) as $i) {
            $who = ($this->makeCustomer)("Customer {$i}", "c{$i}@example.com", '+88019000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            ($this->ask)($who);
        }
        $many = $queries();
        expect($many)->toBe($few)->and($many)->toBe(3);   // the count, the page of requests, and every customer's name in one query
        $this->actingAs($this->manager, 'admin')->get('/admin/privacy?type=export')->assertOk()->assertDontSee('Customer 3');
        $this->actingAs($this->manager, 'admin')->get('/admin/privacy?status=bogus')->assertSessionHasErrors('status');
    });

    it('is shown to staff in English and in Bangla with no missing words', function () {
        ($this->ask)();
        $request = ($this->req)();
        $this->manager->forceFill(['locale' => 'bn'])->save();

        foreach (['/admin/privacy', "/admin/privacy/{$request->public_id}"] as $page) {
            expect($this->actingAs($this->manager, 'admin')->get($page)->assertOk()->getContent())->not->toContain('privacy::');
        }
        $this->actingAs($this->manager, 'admin')->get('/admin/privacy')->assertSee('গোপনীয়তার অনুরোধ');
    });
});
