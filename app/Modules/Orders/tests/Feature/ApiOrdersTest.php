<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\DigitalProducts\Database\Seeders\RefundPolicySeeder;
use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\PhoneProof;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\Settings;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/* Phase 11, slice 5: the customer's orders from the app. Whose order is it — the signed-in owner's, or a guest holding that one order's token — and nobody else's. */

beforeEach(function () {
    $this->withoutMiddleware([ThrottleRequests::class, EnforceSessionPolicy::class]);
    Notification::fake();
    shopWorld($this);
    $this->seed([GeographySeeder::class, ShippingSeeder::class, RefundPolicySeeder::class]);

    $dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->address = ['recipient' => 'Rahim Uddin', 'phone' => '01712345678', 'division_id' => (int) DB::table('districts')->where('id', $dhaka)->value('division_id'), 'district_id' => $dhaka, 'upazila_id' => (int) DB::table('upazilas')->where('district_id', $dhaka)->value('id'), 'address_line' => 'House 5, Road 2', 'area' => 'Dhanmondi', 'postal_code' => '1205'];
    app(Settings::class)->set('fraud.max_open_cod_per_phone', 0); // these tests place many orders for one phone; the limit has its own tests
    $this->amina = User::factory()->verified()->create(['email' => 'amina@example.com']);
    $this->babul = User::factory()->verified()->create(['email' => 'babul@example.com']);
    $this->as = function (User $u, array $extra = []) {
        auth()->forgetGuards();
        auth()->shouldUse((string) config('auth.defaults.guard'));

        return ['Authorization' => 'Bearer '.app(IssueApiSession::class)->start($u, 'Pixel', 'android', 'password')->accessToken] + $extra;
    };
    $this->anon = function (array $extra = []) {
        auth()->forgetGuards();
        auth()->shouldUse((string) config('auth.defaults.guard'));

        return $extra;
    };
    // an order placed through the real checkout: a guest's (returns the order and its token), or a customer's
    $this->place = function (?User $as = null, string $payment = 'cod', array $lines = [], int $total = 0): array {
        $owner = $as === null ? CartOwner::guest($token = bin2hex(random_bytes(20))) : CartOwner::user($as->id);
        foreach ($lines ?: [[$this->tee, 1]] as [$v, $q]) {
            app(AddToCart::class)->handle($owner, $v['variantId'], $q);
        }
        $headers = $as === null ? ['X-Cart-Token' => $token] : ($this->as)($as);
        $body = ['address' => $this->address, 'contact' => ['email' => 'rahim@example.com'], 'shipping_method' => 'standard', 'payment_method' => $payment];
        $quote = test()->postJson('/api/v1/checkout/quote', $body, $headers)->assertOk()->json('data.totals.grand_total.amount_minor');
        $res = test()->postJson('/api/v1/checkout/orders', $body + ['expected_total' => $total ?: $quote], $headers + ['Idempotency-Key' => 'k-'.bin2hex(random_bytes(8))]);
        $res->assertCreated();

        return ['id' => $res->json('data.order.id'), 'token' => $res->json('data.order_token'), 'number' => $res->json('data.order.number'), 'total' => $quote];
    };
});

describe('who may see an order', function () {
    it('gives a guest a token for the order they placed, and a customer none', function () {
        $guest = ($this->place)();
        $mine = ($this->place)($this->amina);

        expect($guest['token'])->toBeString()->not->toBeEmpty()->and($mine['token'])->toBeNull();
    });

    it('lets a guest see exactly that order with the phone masked, and the owner see all of it', function () {
        $guest = ($this->place)();
        $owner = ($this->place)($this->amina);

        $asGuest = $this->getJson("/api/v1/orders/{$guest['id']}", ($this->anon)(['X-Order-Token' => $guest['token']]));
        $asGuest->assertOk()->assertJsonPath('data.number', $guest['number'])->assertJsonPath('data.totals.grand_total.amount_minor', $guest['total'])->assertJsonPath('data.contact.email', null);
        expect($asGuest->json('data.contact.phone'))->not->toBe('+8801712345678')->and($asGuest->json('data.contact.phone'))->toContain('*')->and($asGuest->json('data.delivery_address.phone'))->toContain('*')
            ->and($asGuest->json('data.available_actions'))->toContain('invoice')->not->toContain('cancel')->not->toContain('reorder');
        assertMatchesOpenApi($asGuest, 'GET', "/api/v1/orders/{$guest['id']}");

        $asOwner = $this->getJson("/api/v1/orders/{$owner['id']}", ($this->as)($this->amina));
        $asOwner->assertOk()->assertJsonPath('data.contact.email', 'rahim@example.com')->assertJsonPath('data.contact.phone', '+8801712345678')->assertJsonPath('data.delivery_address.address_line', 'House 5, Road 2')->assertJsonPath('data.items.0.quantity', 1)->assertJsonPath('data.items.0.unit_price.amount_minor', 65000);
        expect($asOwner->json('data.available_actions'))->toContain('cancel', 'reorder', 'invoice');
    });

    it('answers 404 — never 403, never the order — for a missing token, another order’s token, a damaged token and an expired token', function () {
        $a = ($this->place)();
        $b = ($this->place)();

        $this->getJson("/api/v1/orders/{$a['id']}", ($this->anon)())->assertNotFound();
        $this->getJson("/api/v1/orders/{$a['id']}", ($this->anon)(['X-Order-Token' => $b['token']]))->assertNotFound();
        $this->getJson("/api/v1/orders/{$a['id']}", ($this->anon)(['X-Order-Token' => substr($a['token'], 0, -3).'abc']))->assertNotFound();
        $this->getJson("/api/v1/orders/{$a['id']}", ($this->anon)(['X-Order-Token' => 'nonsense']))->assertNotFound();
        $this->getJson('/api/v1/orders/'.str_repeat('0', 26), ($this->anon)(['X-Order-Token' => $a['token']]))->assertNotFound();

        $this->travel(31)->days();
        $this->getJson("/api/v1/orders/{$a['id']}", ($this->anon)(['X-Order-Token' => $a['token']]))->assertNotFound();
    });

    it('treats every order route the same for a stranger: 404 on all of them, and nothing of the order leaks', function () {
        $mine = ($this->place)($this->amina, 'cod');
        $stranger = ($this->as)($this->babul);
        $post = ['Idempotency-Key' => 'stranger-key-001'];

        $calls = [
            ['getJson', "/api/v1/orders/{$mine['id']}", []], ['getJson', "/api/v1/orders/{$mine['id']}/invoice", []], ['getJson', "/api/v1/orders/{$mine['id']}/payment", []],
            ['getJson', "/api/v1/orders/{$mine['id']}/tracking", []], ['getJson', "/api/v1/orders/{$mine['id']}/returns/eligibility", []],
            ['postJson', "/api/v1/orders/{$mine['id']}/payments", $post], ['postJson', "/api/v1/orders/{$mine['id']}/payment/verify", []], ['postJson', "/api/v1/orders/{$mine['id']}/cancel", []],
            ['postJson', "/api/v1/orders/{$mine['id']}/reorder", []], ['postJson', "/api/v1/orders/{$mine['id']}/returns", ['reason_code' => 'damaged', 'lines' => [['position' => 1, 'quantity' => 1]]]],
        ];
        foreach ($calls as [$method, $url, $extra]) {
            $res = $method === 'getJson' ? $this->getJson($url, $stranger + $extra) : $this->postJson($url, $url === "/api/v1/orders/{$mine['id']}/returns" ? $extra : [], $stranger + $post);
            $res->assertNotFound();
            expect($res->getContent())->not->toContain($mine['number'])->not->toContain('Rahim');
        }
        expect(Order::query()->where('public_id', $mine['id'])->value('status'))->toBe(OrderStatus::Pending);
    });
});

describe('what a token can and cannot do', function () {
    it('never lets a token made for another purpose open an order, or an order token confirm a phone', function () {
        $o = ($this->place)();
        $phoneProof = app(PhoneProof::class)->issue('+8801712345678');

        $this->getJson("/api/v1/orders/{$o['id']}", ($this->anon)(['X-Order-Token' => $phoneProof]))->assertNotFound();
        expect(app(PhoneProof::class)->valid($o['token'], '+8801712345678'))->toBeFalse();
    });

    it('lets a signed-in stranger who holds a guest’s token look at that one order — and do nothing else with it', function () {
        $guest = ($this->place)();
        $h = ($this->as)($this->babul, ['X-Order-Token' => $guest['token']]);

        $this->getJson("/api/v1/orders/{$guest['id']}", $h)->assertOk()->assertJsonPath('data.contact.phone', fn ($p) => str_contains((string) $p, '*'));
        $this->postJson("/api/v1/orders/{$guest['id']}/cancel", [], $h)->assertNotFound();
        $this->postJson("/api/v1/orders/{$guest['id']}/reorder", [], $h)->assertNotFound();
        $this->postJson("/api/v1/orders/{$guest['id']}/returns", ['reason_code' => 'damaged', 'lines' => [['position' => 1, 'quantity' => 1]]], $h)->assertNotFound();
        expect(Order::query()->where('public_id', $guest['id'])->value('status'))->toBe(OrderStatus::Pending);
    });
});

describe('my orders', function () {
    it('lists only my orders, newest first, a page at a time by cursor, with nothing missed or repeated', function () {
        $ids = [];
        foreach (range(1, 5) as $_) {
            $ids[] = ($this->place)($this->amina)['id'];
        }
        ($this->place)($this->babul);

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $res = $this->getJson('/api/v1/orders?limit=2'.($cursor ? '&cursor='.$cursor : ''), ($this->as)($this->amina));
            $res->assertOk();
            assertMatchesOpenApi($res, 'GET', '/api/v1/orders');
            $seen = [...$seen, ...array_column($res->json('data'), 'id')];
            $cursor = $res->json('meta.next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 10);

        expect($seen)->toBe(array_reverse($ids))->and($pages)->toBe(3);
    });

    it('refuses a bad limit or cursor, and needs a signed-in customer', function () {
        $this->getJson('/api/v1/orders?limit=100', ($this->as)($this->amina))->assertStatus(422);
        $this->getJson('/api/v1/orders?limit=0', ($this->as)($this->amina))->assertStatus(422);
        $this->getJson('/api/v1/orders?cursor=garbage!', ($this->as)($this->amina))->assertStatus(422);
        $this->getJson('/api/v1/orders', ($this->anon)())->assertStatus(401);
    });

    it('costs the same number of queries for a page of two orders as for a page of ten', function () {
        foreach (range(1, 10) as $_) {
            ($this->place)($this->amina);
        }
        $count = function (int $limit): int {
            $h = ($this->as)($this->amina);
            DB::flushQueryLog();
            DB::enableQueryLog();
            test()->getJson("/api/v1/orders?limit={$limit}", $h)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        expect($count(10))->toBeLessThanOrEqual($count(2) + 1);
    });
});

describe('cancelling', function () {
    it('cancels an order that has not been confirmed or paid, frees its stock, and says so', function () {
        $o = ($this->place)($this->amina, 'cod');
        $res = $this->postJson("/api/v1/orders/{$o['id']}/cancel", ['note' => 'changed my mind'], ($this->as)($this->amina));

        $res->assertOk()->assertJsonPath('data.result', 'cancelled');
        assertMatchesOpenApi($res, 'POST', "/api/v1/orders/{$o['id']}/cancel");
        expect(Order::query()->where('public_id', $o['id'])->value('status'))->toBe(OrderStatus::Cancelled);
        $again = $this->postJson("/api/v1/orders/{$o['id']}/cancel", [], ($this->as)($this->amina));
        $again->assertStatus(409)->assertJsonPath('code', 'order.not_cancellable');
    });

    it('turns a cancel into a request to the shop once the order is being handled, but not after it shipped', function () {
        $o = ($this->place)($this->amina, 'cod');
        $order = Order::query()->where('public_id', $o['id'])->firstOrFail();
        app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Confirmed, 'test');

        $ask = $this->postJson("/api/v1/orders/{$o['id']}/cancel", ['note' => 'please'], ($this->as)($this->amina));
        $ask->assertStatus(202)->assertJsonPath('data.result', 'requested');
        expect(DB::table('order_cancellation_requests')->where('order_id', $order->id)->where('status', 'requested')->count())->toBe(1)
            ->and($this->getJson("/api/v1/orders/{$o['id']}", ($this->as)($this->amina))->json('data.cancellation_request'))->toBe('requested');
        $this->postJson("/api/v1/orders/{$o['id']}/cancel", [], ($this->as)($this->amina))->assertStatus(409)->assertJsonPath('code', 'order.not_cancellable');

        DB::table('orders')->where('id', $order->id)->update(['fulfillment_status' => 'fulfilled']);
        DB::table('order_cancellation_requests')->delete();
        $this->postJson("/api/v1/orders/{$o['id']}/cancel", [], ($this->as)($this->amina))->assertStatus(409)->assertJsonPath('code', 'order.not_cancellable');
    });

    it('is not offered to a guest’s token: the order stays', function () {
        $guest = ($this->place)();

        $this->postJson("/api/v1/orders/{$guest['id']}/cancel", [], ($this->anon)(['X-Order-Token' => $guest['token']]))->assertStatus(401);
        expect(Order::query()->where('public_id', $guest['id'])->value('status'))->toBe(OrderStatus::Pending);
    });
});

describe('buying again and the invoice', function () {
    it('puts the order’s items in the cart at today’s price and says what went in', function () {
        $o = ($this->place)($this->amina, 'cod', [[$this->tee, 2]]);
        ($this->setPrice)($this->tee, 700);

        $res = $this->postJson("/api/v1/orders/{$o['id']}/reorder", [], ($this->as)($this->amina));
        $res->assertOk()->assertJsonPath('data.0.requested', 2)->assertJsonPath('data.0.added', 2)->assertJsonPath('data.0.status', fn ($s) => is_string($s));
        assertMatchesOpenApi($res, 'POST', "/api/v1/orders/{$o['id']}/reorder");
        $cart = $this->getJson('/api/v1/cart', ($this->as)($this->amina));
        expect($cart->json('data.lines.0.quantity'))->toBe(2)->and($cart->json('data.lines.0.unit_price.amount_minor'))->toBe(70000);
    });

    it('gives a short-lived signed link to the receipt that works, and stops working when changed or old', function () {
        $o = ($this->place)($this->amina);
        $res = $this->getJson("/api/v1/orders/{$o['id']}/invoice", ($this->as)($this->amina));

        $res->assertOk();
        assertMatchesOpenApi($res, 'GET', "/api/v1/orders/{$o['id']}/invoice");
        $url = $res->json('data.url');
        $this->get($url)->assertOk()->assertSee($o['number']);
        $this->get(preg_replace('/signature=[^&]+/', 'signature=bad', $url))->assertForbidden();
        $this->travel(31)->minutes();
        $this->get($url)->assertForbidden();

        $guest = ($this->place)();
        $this->getJson("/api/v1/orders/{$guest['id']}/invoice", ($this->anon)(['X-Order-Token' => $guest['token']]))->assertOk();
    });
});
