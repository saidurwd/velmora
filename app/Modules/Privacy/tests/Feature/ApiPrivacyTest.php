<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Phase 11, slice 6a: the customer's privacy requests from the app. A copy of their data and erasure of their account. Always their own; every request needs this
 * sign-in to have just confirmed it is them. The data copy goes only to that confirmed, signed-in caller, never to a link anyone could open.
 */

beforeEach(function () {
    privacyWorld($this);
    $this->withoutMiddleware([ThrottleRequests::class, EnforceSessionPolicy::class]);
    $this->amina = ($this->makeCustomer)('Amina Rahman', 'amina@example.com', '+8801711111111');
    $this->bob = ($this->makeCustomer)('Bob Karim', 'bob@example.com', '+8801822222222');
    DB::table('users')->update(['password' => Hash::make('Tr0ub4dor-and-3-horses')]);
    $this->signIn = function (array $who) {
        auth()->forgetGuards();

        return ['Authorization' => 'Bearer '.app(IssueApiSession::class)->start($who['user'], 'Pixel', 'android', 'password')->accessToken];
    };
    // a sign-in that has just confirmed it is the person
    $this->confirmed = function (array $who) {
        $h = ($this->signIn)($who);
        auth()->forgetGuards();
        test()->postJson('/api/v1/auth/reauth', ['password' => 'Tr0ub4dor-and-3-horses'], $h)->assertNoContent();
        auth()->forgetGuards();

        return $h;
    };
    $this->requestOf = fn (array $who, string $type) => DB::table('privacy_requests')->where('user_id', $who['user']->id)->where('type', $type)->orderByDesc('id')->first();
});

describe('a copy of my data', function () {
    it('needs a fresh confirmation of this sign-in, and a sign-in', function () {
        $this->postJson('/api/v1/me/privacy/export', [], ($this->signIn)($this->amina))->assertStatus(403)->assertJsonPath('code', 'auth.reauth_required');
        auth()->forgetGuards();
        $this->postJson('/api/v1/me/privacy/export')->assertStatus(401);
        expect(DB::table('privacy_requests')->count())->toBe(0);
    });

    it('is asked for, built, and downloaded by its owner as a file that is never cached', function () {
        $h = ($this->confirmed)($this->amina);
        $ask = $this->postJson('/api/v1/me/privacy/export', [], $h);
        $ask->assertStatus(202)->assertJsonPath('data.status', 'ready');
        assertMatchesOpenApi($ask, 'POST', '/api/v1/me/privacy/export');

        auth()->forgetGuards();
        $res = $this->getJson('/api/v1/me/privacy/exports/'.$ask->json('data.id'), $h);
        $res->assertOk();
        expect($res->headers->get('Content-Type'))->toContain('application/json')->and($res->headers->get('Content-Disposition'))->toContain('attachment')->and($res->headers->get('Cache-Control'))->toContain('no-store')
            ->and(json_decode($res->getContent(), true)['account']['email'])->toBe('amina@example.com');
        expect(DB::table('audit_events')->where('action', 'privacy.export_downloaded')->count())->toBe(1);
    });

    it('is refused a second time in a day, and is never another customer’s', function () {
        $h = ($this->confirmed)($this->amina);
        $first = $this->postJson('/api/v1/me/privacy/export', [], $h)->assertStatus(202);
        auth()->forgetGuards();
        $this->postJson('/api/v1/me/privacy/export', [], $h)->assertStatus(409)->assertJsonPath('code', 'privacy.request_refused');

        auth()->forgetGuards();
        $bob = ($this->confirmed)($this->bob);
        $this->getJson('/api/v1/me/privacy/exports/'.$first->json('data.id'), $bob)->assertNotFound();
        auth()->forgetGuards();
        $this->getJson('/api/v1/me/privacy/exports/'.str_repeat('0', 26), $bob)->assertNotFound();
        expect(DB::table('audit_events')->where('action', 'privacy.export_downloaded')->count())->toBe(0);
    });

    it('needs the fresh confirmation to download, too', function () {
        $h = ($this->confirmed)($this->amina);
        $id = $this->postJson('/api/v1/me/privacy/export', [], $h)->json('data.id');

        $this->getJson("/api/v1/me/privacy/exports/{$id}", ($this->signIn)($this->amina))->assertStatus(403)->assertJsonPath('code', 'auth.reauth_required');
    });
});

describe('erasing my account', function () {
    it('asks for it, says what stands in the way, and changes nothing until the shop decides', function () {
        $h = ($this->confirmed)($this->amina);

        $res = $this->postJson('/api/v1/me/privacy/erasure', ['understand' => true, 'reason' => 'I no longer shop here'], $h);
        $res->assertStatus(202)->assertJsonPath('data.status', 'requested');
        assertMatchesOpenApi($res, 'POST', '/api/v1/me/privacy/erasure');
        expect(DB::table('users')->where('id', $this->amina['user']->id)->value('email'))->toBe('amina@example.com')->and(DB::table('users')->where('id', $this->amina['user']->id)->value('status'))->toBe('active');

        auth()->forgetGuards();
        $list = $this->getJson('/api/v1/me/privacy', $h);
        $list->assertOk()->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.type', 'erasure');
        assertMatchesOpenApi($list, 'GET', '/api/v1/me/privacy');
        expect($list->json('data.erasure_blockers'))->toBeArray();
    });

    it('needs the customer to say they understand, and refuses asking twice', function () {
        $h = ($this->confirmed)($this->amina);

        $this->postJson('/api/v1/me/privacy/erasure', [], $h)->assertStatus(422)->assertJsonValidationErrors(['understand']);
        auth()->forgetGuards();
        $this->postJson('/api/v1/me/privacy/erasure', ['understand' => false], $h)->assertStatus(422);
        auth()->forgetGuards();
        $this->postJson('/api/v1/me/privacy/erasure', ['understand' => true], $h)->assertStatus(202);
        auth()->forgetGuards();
        $this->postJson('/api/v1/me/privacy/erasure', ['understand' => true], $h)->assertStatus(409)->assertJsonPath('code', 'privacy.request_refused');
    });

    it('can be cancelled by its owner while it waits, and by nobody else', function () {
        $h = ($this->confirmed)($this->amina);
        $id = $this->postJson('/api/v1/me/privacy/erasure', ['understand' => true], $h)->json('data.id');

        auth()->forgetGuards();
        $this->postJson("/api/v1/me/privacy/erasure/{$id}/cancel", [], ($this->confirmed)($this->bob))->assertNotFound();
        expect(($this->requestOf)($this->amina, 'erasure')->status)->toBe('requested');

        auth()->forgetGuards();
        $this->postJson("/api/v1/me/privacy/erasure/{$id}/cancel", [], $h)->assertNoContent();
        expect(($this->requestOf)($this->amina, 'erasure')->status)->not->toBe('requested');
        auth()->forgetGuards();
        $this->postJson("/api/v1/me/privacy/erasure/{$id}/cancel", [], $h)->assertStatus(409)->assertJsonPath('code', 'privacy.request_refused');
    });

    it('lists only my own requests', function () {
        $this->postJson('/api/v1/me/privacy/erasure', ['understand' => true], ($this->confirmed)($this->bob))->assertStatus(202);

        auth()->forgetGuards();
        $this->getJson('/api/v1/me/privacy', ($this->signIn)($this->amina))->assertOk()->assertJsonPath('data.requests', []);
    });
});
