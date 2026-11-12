<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Orders\Contracts\ApiOrderAccess;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/* Phase 11, slice 5: where is my order, and sending things back — for the app. The same services as the website's tracking page and returns portal. */

beforeEach(function () {
    shippingWorld($this);
    $this->seed([PaymentMethodSeeder::class]);
    $this->withoutMiddleware([ThrottleRequests::class, EnforceSessionPolicy::class]);
    Mail::fake();
    $this->amina = User::factory()->verified()->create(['email' => 'amina@example.com']);
    $this->babul = User::factory()->verified()->create(['email' => 'babul@example.com']);
    $this->as = function (User $u) {
        auth()->forgetGuards();
        auth()->shouldUse((string) config('auth.defaults.guard'));

        return ['Authorization' => 'Bearer '.app(IssueApiSession::class)->start($u, 'Pixel', 'android', 'password')->accessToken];
    };
    $this->anon = function (array $extra = []) {
        auth()->forgetGuards();
        auth()->shouldUse((string) config('auth.defaults.guard'));

        return $extra;
    };
    /** A cash order of two tees (line 1) and a mug (line 2), owned by $owner. */
    $this->mine = function (?User $owner = null, bool $deliver = false) {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1], 'cod');
        DB::table('orders')->where('id', $order->id)->update(['user_id' => ($owner ?? $this->amina)->id]);
        $parcel = ($this->parcel)($order, [$this->tee => 2, $this->mug => 1]);
        $deliver && ($this->say)($parcel, 'DELIVERED', now()->addMinute()->format('Y-m-d H:i:s'));

        return DB::table('orders')->where('id', $order->id)->first();
    };
});

describe('tracking', function () {
    it('shows each parcel, its items and its timeline, with no address or phone, to the owner', function () {
        $order = ($this->mine)();
        $res = $this->getJson("/api/v1/orders/{$order->public_id}/tracking", ($this->as)($this->amina));

        $res->assertOk()->assertJsonPath('data.number', $order->order_number)->assertJsonCount(1, 'data.parcels')->assertJsonPath('data.parcels.0.items.0.quantity', 2)->assertJsonPath('data.delivered', false);
        assertMatchesOpenApi($res, 'GET', "/api/v1/orders/{$order->public_id}/tracking");
        expect($res->getContent())->not->toContain('01712345678')->not->toContain('Rahim')->not->toContain('Dhaka');
    });

    it('follows the courier’s events to delivered', function () {
        $order = ($this->mine)(null, true);
        $res = $this->getJson("/api/v1/orders/{$order->public_id}/tracking", ($this->as)($this->amina));

        $res->assertOk()->assertJsonPath('data.delivered', true)->assertJsonPath('data.parcels.0.status', 'delivered');
        expect(collect($res->json('data.parcels.0.events'))->pluck('status')->all())->toContain('delivered');
    });

    it('works for a guest holding that order’s token, and for nobody else', function () {
        $order = ($this->mine)();
        $token = app(ApiOrderAccess::class)->issueToken($order->public_id);
        $other = ($this->mine)($this->babul);

        $this->getJson("/api/v1/orders/{$order->public_id}/tracking", ($this->anon)(['X-Order-Token' => $token]))->assertOk();
        $this->getJson("/api/v1/orders/{$other->public_id}/tracking", ($this->anon)(['X-Order-Token' => $token]))->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->public_id}/tracking", ($this->anon)())->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->public_id}/tracking", ($this->as)($this->babul))->assertNotFound();
    });
});

describe('sending things back', function () {
    it('says which lines can go back, and nothing is open before delivery', function () {
        $sent = ($this->mine)();
        $before = $this->getJson("/api/v1/orders/{$sent->public_id}/returns/eligibility", ($this->as)($this->amina));
        $before->assertOk()->assertJsonPath('data.lines.0.open', false);
        assertMatchesOpenApi($before, 'GET', "/api/v1/orders/{$sent->public_id}/returns/eligibility");

        $done = ($this->mine)(null, true);
        $after = $this->getJson("/api/v1/orders/{$done->public_id}/returns/eligibility", ($this->as)($this->amina));
        $after->assertOk()->assertJsonPath('data.lines.0.position', 1)->assertJsonPath('data.lines.0.left', 2)->assertJsonPath('data.lines.0.open', true);
        expect($after->json('data.reasons'))->toContain('damaged', 'wrong_item');
    });

    it('takes a return for delivered items, lists it, and lets the owner withdraw it', function () {
        $order = ($this->mine)(null, true);
        $h = fn () => ($this->as)($this->amina);

        $made = $this->postJson("/api/v1/orders/{$order->public_id}/returns", ['reason_code' => 'damaged', 'note' => 'cracked', 'lines' => [['position' => 1, 'quantity' => 1]]], $h());
        $made->assertCreated()->assertJsonPath('data.status', 'requested');
        assertMatchesOpenApi($made, 'POST', "/api/v1/orders/{$order->public_id}/returns");
        expect((int) DB::table('return_items')->sum('quantity'))->toBe(1);

        $list = $this->getJson('/api/v1/returns', $h());
        $list->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.order', $order->order_number)->assertJsonPath('data.0.items.0.quantity', 1);
        assertMatchesOpenApi($list, 'GET', '/api/v1/returns');

        $this->postJson("/api/v1/returns/{$made->json('data.id')}/withdraw", [], $h())->assertNoContent();
        $this->postJson("/api/v1/returns/{$made->json('data.id')}/withdraw", [], $h())->assertStatus(422)->assertJsonPath('code', 'return.not_eligible');
    });

    it('refuses a return before delivery, for more than was delivered, for a reason that is not on the list, with the reason in words', function () {
        $sent = ($this->mine)();
        $done = ($this->mine)(null, true);
        $h = fn () => ($this->as)($this->amina);
        $ask = fn (object $o, array $lines, array $over = []) => $this->postJson("/api/v1/orders/{$o->public_id}/returns", $over + ['reason_code' => 'damaged', 'lines' => $lines], $h());

        $early = $ask($sent, [['position' => 1, 'quantity' => 1]]);
        $early->assertStatus(422)->assertJsonPath('code', 'return.not_eligible');
        expect($early->json('detail'))->not->toBeEmpty();
        $ask($done, [['position' => 1, 'quantity' => 3]])->assertStatus(422)->assertJsonPath('code', 'return.not_eligible');
        $ask($done, [['position' => 1, 'quantity' => 1]], ['reason_code' => 'because'])->assertStatus(422)->assertJsonValidationErrors(['reason_code']);
        $ask($done, [])->assertStatus(422)->assertJsonValidationErrors(['lines']);
        $ask($done, [['position' => 1, 'quantity' => 1], ['position' => 1, 'quantity' => 1]])->assertStatus(422);
        $ask($done, [['position' => 9, 'quantity' => 1]])->assertNotFound();
        expect(DB::table('return_requests')->count())->toBe(0);
    });

    it('is the owner’s alone: another customer’s order and return are 404, and a guest token cannot start one', function () {
        $order = ($this->mine)(null, true);
        $made = $this->postJson("/api/v1/orders/{$order->public_id}/returns", ['reason_code' => 'damaged', 'lines' => [['position' => 1, 'quantity' => 1]]], ($this->as)($this->amina))->assertCreated();

        $this->postJson("/api/v1/orders/{$order->public_id}/returns", ['reason_code' => 'damaged', 'lines' => [['position' => 2, 'quantity' => 1]]], ($this->as)($this->babul))->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->public_id}/returns/eligibility", ($this->as)($this->babul))->assertNotFound();
        $this->postJson("/api/v1/returns/{$made->json('data.id')}/withdraw", [], ($this->as)($this->babul))->assertNotFound();
        expect($this->getJson('/api/v1/returns', ($this->as)($this->babul))->json('data'))->toBe([]);

        $token = app(ApiOrderAccess::class)->issueToken($order->public_id);
        $this->postJson("/api/v1/orders/{$order->public_id}/returns", ['reason_code' => 'damaged', 'lines' => [['position' => 1, 'quantity' => 1]]], ($this->anon)(['X-Order-Token' => $token]))->assertStatus(401);
        expect(DB::table('return_requests')->count())->toBe(1);
    });
});
