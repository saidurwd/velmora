<?php

declare(strict_types=1);

use App\Modules\DigitalProducts\Actions\ImportLicenseKeys;
use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

/* Phase 11, slice 6b: my digital purchases for the app. Each download is counted once against the purchase's own limit and expiry, and recorded. */

beforeEach(function () {
    digitalWorld($this);
    $this->withoutMiddleware([ThrottleRequests::class, EnforceSessionPolicy::class]);
    $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    $this->stranger = User::factory()->create(['email' => 'stranger@example.com']);
    $this->as = function (User $u) {
        auth()->forgetGuards();

        return ['Authorization' => 'Bearer '.app(IssueApiSession::class)->start($u, 'Pixel', 'android', 'password')->accessToken];
    };
    /** A paid order of a book for $for, with its file uploaded. Returns the entitlement row. */
    $this->bought = function (string $sku = 'BOOK-1', ?User $for = null, bool $file = true) {
        $order = ($this->place)([$sku === 'BOOK-1' ? $this->book : $this->book2 => 1], ($for ?? $this->owner)->id);
        ($this->pay)($order->id);
        $file && ($this->upload)($sku, 'Cooking Book.pdf');

        return DB::table('entitlements')->where('order_id', $order->id)->first();
    };
    $this->used = fn (int $id) => (int) DB::table('entitlements')->where('id', $id)->value('downloads_used');
    $this->log = fn (?string $outcome = null) => DB::table('download_logs')->when($outcome, fn ($q) => $q->where('outcome', $outcome))->orderBy('id')->get();
});

describe('my library', function () {
    it('lists my purchases with the file, the version and what is left, and nobody else’s', function () {
        ($this->bought)();
        ($this->bought)('BOOK-2', $this->stranger);

        $res = $this->getJson('/api/v1/library', ($this->as)($this->owner));
        $res->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.product', 'Cooking e-book')->assertJsonPath('data.0.file', 'Cooking Book.pdf')->assertJsonPath('data.0.version', 1)->assertJsonPath('data.0.state', 'available')->assertJsonPath('data.0.unlimited', false);
        assertMatchesOpenApi($res, 'GET', '/api/v1/library');
        expect(json_encode($res->json()))->not->toContain('files.test')->not->toContain('digital/')->not->toContain('Garden');
        $this->getJson('/api/v1/library', ($this->as)($this->stranger))->assertJsonPath('data.0.product', 'Garden e-book');
    });

    it('says when a file is not ready yet, and costs the same number of queries for one purchase as for several', function () {
        ($this->bought)('BOOK-1', null, false);
        $this->getJson('/api/v1/library', ($this->as)($this->owner))->assertJsonPath('data.0.state', 'not_ready');

        $count = function (): int {
            $h = ($this->as)($this->owner);
            DB::flushQueryLog();
            DB::enableQueryLog();
            test()->getJson('/api/v1/library', $h)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $one = $count();
        ($this->bought)('BOOK-2');
        ($this->bought)('BOOK-1');
        expect($count())->toBeLessThanOrEqual($one + 1);
    });

    it('needs a signed-in customer', function () {
        $this->getJson('/api/v1/library')->assertStatus(401);
        auth()->forgetGuards();
        $this->postJson('/api/v1/library/'.str_repeat('0', 26).'/download')->assertStatus(401);
    });
});

describe('downloading', function () {
    it('gives a short-lived address for the file, uses one download, and writes it down', function () {
        $right = ($this->bought)();
        $res = $this->postJson("/api/v1/library/{$right->public_id}/download", [], ($this->as)($this->owner));

        $res->assertOk()->assertJsonPath('data.filename', 'Cooking Book.pdf')->assertJsonPath('data.remaining', 4);
        assertMatchesOpenApi($res, 'POST', "/api/v1/library/{$right->public_id}/download");
        expect($res->json('data.url'))->toStartWith('https://files.test/')->and($res->headers->get('Cache-Control'))->toContain('no-store')
            ->and(($this->used)($right->id))->toBe(1)->and(($this->log)('granted'))->toHaveCount(1)
            ->and(CarbonImmutable::parse($res->json('data.expires_at'))->diffInSeconds(now(), true))->toBeLessThan(120);
    });

    it('never goes past the limit, and each refusal says why and uses up nothing', function () {
        $right = ($this->bought)();
        DB::table('entitlements')->where('id', $right->id)->update(['download_limit' => 1]);
        $h = fn () => ($this->as)($this->owner);

        $this->postJson("/api/v1/library/{$right->public_id}/download", [], $h())->assertOk();
        $over = $this->postJson("/api/v1/library/{$right->public_id}/download", [], $h());
        $over->assertStatus(409)->assertJsonPath('code', 'entitlement.limit_reached');
        expect($over->json('detail'))->not->toBeEmpty()->and(($this->used)($right->id))->toBe(1)->and(($this->log)('limit_reached'))->toHaveCount(1);

        DB::table('entitlements')->where('id', $right->id)->update(['download_limit' => null, 'expires_at' => now()->subMinute()]);
        $this->postJson("/api/v1/library/{$right->public_id}/download", [], $h())->assertStatus(409)->assertJsonPath('code', 'entitlement.expired');
        DB::table('entitlements')->where('id', $right->id)->update(['expires_at' => null, 'status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => 'refund']);
        $this->postJson("/api/v1/library/{$right->public_id}/download", [], $h())->assertStatus(409)->assertJsonPath('code', 'entitlement.revoked');
        expect(($this->used)($right->id))->toBe(1);
    });

    it('is refused with a clear code, and costs nothing, when the file is not there yet', function () {
        $right = ($this->bought)('BOOK-1', null, false);

        $this->postJson("/api/v1/library/{$right->public_id}/download", [], ($this->as)($this->owner))->assertStatus(409)->assertJsonPath('code', 'entitlement.unavailable');
        expect(($this->used)($right->id))->toBe(0)->and(($this->log)('no_file'))->toHaveCount(1);
    });

    it('is 404 for somebody else’s purchase, the attempt is written down, and nothing is used', function () {
        $right = ($this->bought)();

        $this->postJson("/api/v1/library/{$right->public_id}/download", [], ($this->as)($this->stranger))->assertNotFound();
        $this->postJson("/api/v1/library/{$right->public_id}/keys", [], ($this->as)($this->stranger))->assertNotFound();
        $this->postJson('/api/v1/library/'.str_repeat('0', 26).'/download', [], ($this->as)($this->stranger))->assertNotFound();
        expect(($this->used)($right->id))->toBe(0)->and(($this->log)('not_owner'))->toHaveCount(2);
    });
});

describe('licence keys', function () {
    it('shows the keys given to my purchase, never to anyone else, and never keeps them in a cache', function () {
        $staff = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Amina');
        app(ImportLicenseKeys::class)->handle($staff, 'BOOK-1', "SECRET-KEY-ONE\nSECRET-KEY-TWO");
        $right = ($this->bought)();

        $res = $this->postJson("/api/v1/library/{$right->public_id}/keys", [], ($this->as)($this->owner));
        $res->assertOk();
        assertMatchesOpenApi($res, 'POST', "/api/v1/library/{$right->public_id}/keys");
        expect($res->json('data.keys'))->toBeArray()->and($res->headers->get('Cache-Control'))->toContain('no-store');

        $stranger = $this->postJson("/api/v1/library/{$right->public_id}/keys", [], ($this->as)($this->stranger));
        $stranger->assertNotFound();
        expect($stranger->getContent())->not->toContain('SECRET');
    });

    it('is refused for a revoked purchase', function () {
        $right = ($this->bought)();
        DB::table('entitlements')->where('id', $right->id)->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => 'refund']);

        $this->postJson("/api/v1/library/{$right->public_id}/keys", [], ($this->as)($this->owner))->assertStatus(409)->assertJsonPath('code', 'entitlement.revoked');
    });
});
