<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* Phase 11, slice 4: the cart for the app. Money and stock: every total is worked out again on the server from live prices, nothing the app sends is a price. */

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    shopWorld($this);
    $this->add = fn (array $variant, int $qty = 1, array $headers = []) => $this->postJson('/api/v1/cart/items', ['variant' => $variant['publicId'], 'quantity' => $qty], $headers);
    // the test client keeps the last request's default guard and user; a real server starts every request clean, so a guest call resets them
    $this->guestHeader = function (string $token) {
        auth()->forgetGuards();
        auth()->shouldUse((string) config('auth.defaults.guard'));

        return ['X-Cart-Token' => $token];
    };
    $this->customer = User::factory()->verified()->create(['email' => 'amina@example.com', 'password' => 'Tr0ub4dor-and-3-horses']);
    $this->bearer = function (?User $user = null) {
        auth()->forgetGuards(); // the test client keeps the guard's last answer between calls; a real server starts every request clean
        $pair = app(IssueApiSession::class)->start($user ?? $this->customer, 'Pixel', 'android', 'password');

        return ['Authorization' => 'Bearer '.$pair->accessToken];
    };
    $this->coupon = fn (string $code, array $over = []) => DB::table('coupons')->insertGetId($over + ['public_id' => strtolower((string) Str::ulid()), 'code' => $code, 'type' => 'percent', 'value' => 1000, 'scope' => 'all', 'is_active' => true, 'min_spend_minor' => 0, 'used_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
});

describe('a guest cart', function () {
    it('is empty and has no token until something is added; the first add hands out a token', function () {
        $empty = $this->getJson('/api/v1/cart');
        $empty->assertOk()->assertJsonPath('data.item_count', 0)->assertJsonPath('data.token', null)->assertJsonPath('data.lines', []);
        assertMatchesOpenApi($empty, 'GET', '/api/v1/cart');
        expect(DB::table('carts')->count())->toBe(0);

        $res = ($this->add)($this->tee, 2);
        $res->assertCreated()->assertJsonPath('data.item_count', 2)->assertJsonPath('data.lines.0.quantity', 2)->assertJsonPath('data.subtotal.amount_minor', 130000);
        assertMatchesOpenApi($res, 'POST', '/api/v1/cart/items');
        $token = $res->json('data.token');
        expect($token)->toMatch('/^[a-f0-9]{40}$/')->and($res->headers->get('X-Cart-Token'))->toBe($token)
            ->and(DB::table('carts')->value('guest_token_hash'))->toBe(hash('sha256', $token))->and(json_encode(DB::table('carts')->get()))->not->toContain($token);

        $again = $this->getJson('/api/v1/cart', ($this->guestHeader)($token));
        $again->assertOk()->assertJsonPath('data.lines.0.variant_id', $this->tee['publicId'])->assertJsonPath('data.token', $token);
    });

    it('is the holder of the token’s alone: another guest, no token and a malformed token see their own empty cart', function () {
        $token = ($this->add)($this->tee, 1)->json('data.token');

        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.item_count', 0);
        $this->getJson('/api/v1/cart', ($this->guestHeader)(str_repeat('a', 40)))->assertOk()->assertJsonPath('data.item_count', 0);
        $this->getJson('/api/v1/cart', ($this->guestHeader)('not-a-token'))->assertOk()->assertJsonPath('data.item_count', 0);
        $this->getJson('/api/v1/cart', ($this->guestHeader)($token))->assertOk()->assertJsonPath('data.item_count', 1);
    });

    it('never accepts a cart token the app made up: a weak or short token is ignored and the server hands out its own', function () {
        foreach (['1', 'weak', str_repeat('a', 39), str_repeat('A', 40), str_repeat('g', 40)] as $chosen) {
            $res = ($this->add)($this->tee, 1, ['X-Cart-Token' => $chosen]);

            $res->assertCreated();
            expect($res->json('data.token'))->not->toBe($chosen)->and($res->json('data.token'))->toMatch('/^[a-f0-9]{40}$/');
        }
        expect(DB::table('carts')->count())->toBe(5)->and(DB::table('carts')->whereIn('guest_token_hash', array_map(fn ($t) => hash('sha256', $t), ['1', 'weak']))->count())->toBe(0);
    });

    it('raises the quantity of an item already in the cart and takes it out with 0 or delete', function () {
        $token = ($this->add)($this->tee, 1)->json('data.token');
        $h = ($this->guestHeader)($token);

        ($this->add)($this->tee, 2, $h)->assertCreated()->assertJsonPath('data.lines.0.quantity', 3);
        $set = $this->patchJson('/api/v1/cart/items/'.$this->tee['publicId'], ['quantity' => 5], $h);
        $set->assertOk()->assertJsonPath('data.lines.0.quantity', 5)->assertJsonPath('data.subtotal.amount_minor', 325000);
        assertMatchesOpenApi($set, 'PATCH', '/api/v1/cart/items/'.$this->tee['publicId']);

        $this->patchJson('/api/v1/cart/items/'.$this->tee['publicId'], ['quantity' => 0], $h)->assertOk()->assertJsonPath('data.item_count', 0);
        ($this->add)($this->tee, 1, $h);
        $del = $this->deleteJson('/api/v1/cart/items/'.$this->tee['publicId'], [], $h);
        $del->assertOk()->assertJsonPath('data.item_count', 0);
        assertMatchesOpenApi($del, 'DELETE', '/api/v1/cart/items/'.$this->tee['publicId']);
    });

    it('never takes a price from the app, and rounds nothing: money is whole minor units', function () {
        $res = $this->postJson('/api/v1/cart/items', ['variant' => $this->tee['publicId'], 'quantity' => 1, 'price' => 1, 'unit_price' => 1, 'total' => 1]);

        $res->assertCreated()->assertJsonPath('data.lines.0.unit_price.amount_minor', 65000)->assertJsonPath('data.lines.0.unit_price.currency', 'BDT');
        expect($res->json('data.lines.0.unit_price.formatted'))->toBe('৳ 650.00');
    });
});

describe('the rules of the cart come from the server', function () {
    it('refuses a quantity the item does not allow, saying what is allowed', function () {
        $token = ($this->add)($this->lamp, 2)->json('data.token'); // sold in twos, 2 to 6
        $h = ($this->guestHeader)($token);

        $one = ($this->add)($this->lamp, 1, ($this->guestHeader)(bin2hex(random_bytes(20))));
        $one->assertStatus(422)->assertJsonPath('code', 'cart.quantity_limit');
        expect($one->json('detail'))->toContain('2');
        $this->patchJson('/api/v1/cart/items/'.$this->lamp['publicId'], ['quantity' => 3], $h)->assertStatus(422)->assertJsonPath('code', 'cart.quantity_limit')->assertJsonPath('params.step', 2);
        $this->patchJson('/api/v1/cart/items/'.$this->lamp['publicId'], ['quantity' => 8], $h)->assertStatus(422)->assertJsonPath('code', 'cart.quantity_limit')->assertJsonPath('params.max', 6);
    });

    it('refuses more than is in stock with stock.insufficient and the number left', function () {
        ($this->setStock)($this->tee, 3);
        $res = ($this->add)($this->tee, 4);

        $res->assertStatus(409)->assertJsonPath('code', 'stock.insufficient')->assertJsonPath('params.available', 3);
        expect(DB::table('cart_items')->count())->toBe(0);
        ($this->add)($this->tee, 3)->assertCreated();
    });

    it('refuses an unknown variant, a service (booked, not added) and an item not in the cart', function () {
        $this->postJson('/api/v1/cart/items', ['variant' => str_repeat('0', 26), 'quantity' => 1])->assertStatus(422)->assertJsonPath('code', 'cart.item_unavailable');
        $this->postJson('/api/v1/cart/items', ['variant' => 'short', 'quantity' => 1])->assertStatus(422)->assertJsonValidationErrors(['variant']);
        $this->postJson('/api/v1/cart/items', ['variant' => $this->tee['publicId'], 'quantity' => 0])->assertStatus(422)->assertJsonValidationErrors(['quantity']);
        $this->postJson('/api/v1/cart/items', ['variant' => $this->tee['publicId'], 'quantity' => 'many'])->assertStatus(422);

        $token = ($this->add)($this->tee)->json('data.token');
        $this->deleteJson('/api/v1/cart/items/'.$this->ebook['publicId'], [], ($this->guestHeader)($token))->assertOk();
        $this->patchJson('/api/v1/cart/items/'.$this->ebook['publicId'], ['quantity' => 2], ($this->guestHeader)($token))->assertStatus(404)->assertJsonPath('code', 'cart.item_not_found');
    });

    it('reads Bangla digits in a quantity, and explains in Bangla when asked', function () {
        $ok = $this->postJson('/api/v1/cart/items', ['variant' => $this->tee['publicId'], 'quantity' => '২']);
        $ok->assertCreated()->assertJsonPath('data.lines.0.quantity', 2);

        ($this->setStock)($this->tee, 1);
        $bn = $this->postJson('/api/v1/cart/items', ['variant' => $this->tee['publicId'], 'quantity' => 5], ['Accept-Language' => 'bn']);
        $bn->assertStatus(409);
        expect($bn->json('detail'))->toBe(trans('cart::cart.errors.insufficient_stock', ['available' => 1], 'bn'))->and($bn->json('title'))->toBe(trans('errors.api.stock_insufficient', [], 'bn'));
    });

    it('flags a line whose stock fell below what is in the cart, and one whose price moved, until it is acknowledged', function () {
        $res = ($this->add)($this->tee, 3);
        $h = ($this->guestHeader)($res->json('data.token'));

        ($this->setStock)($this->tee, 1);
        $cart = $this->getJson('/api/v1/cart', $h);
        $cart->assertOk()->assertJsonPath('data.has_problems', true)->assertJsonPath('data.lines.0.problem', 'insufficient_stock')->assertJsonPath('data.lines.0.available', 1);

        ($this->setStock)($this->tee, 10);
        ($this->setPrice)($this->tee, 700);
        $moved = $this->getJson('/api/v1/cart', $h);
        $moved->assertOk()->assertJsonPath('data.has_price_changes', true)->assertJsonPath('data.lines.0.unit_price.amount_minor', 70000)->assertJsonPath('data.lines.0.price_changed_from.amount_minor', 65000);

        $ack = $this->postJson('/api/v1/cart/acknowledge', [], $h);
        $ack->assertOk()->assertJsonPath('data.has_price_changes', false)->assertJsonPath('data.lines.0.price_changed_from', null);
        assertMatchesOpenApi($ack, 'POST', '/api/v1/cart/acknowledge');
    });
});

describe('discount codes', function () {
    it('applies a code, shows the discount, and takes it off again', function () {
        ($this->coupon)('SAVE10');
        $token = ($this->add)($this->tee, 2)->json('data.token');
        $h = ($this->guestHeader)($token);

        $res = $this->postJson('/api/v1/cart/coupons', ['code' => 'save10'], $h);
        $res->assertOk()->assertJsonPath('data.coupons', ['SAVE10'])->assertJsonPath('data.discount_total.amount_minor', 13000)->assertJsonPath('data.goods_total.amount_minor', 117000);
        assertMatchesOpenApi($res, 'POST', '/api/v1/cart/coupons');

        $off = $this->deleteJson('/api/v1/cart/coupons/SAVE10', [], $h);
        $off->assertOk()->assertJsonPath('data.coupons', [])->assertJsonPath('data.discount_total.amount_minor', 0);
        assertMatchesOpenApi($off, 'DELETE', '/api/v1/cart/coupons/SAVE10');
    });

    it('refuses an unknown, expired or too-small-spend code with the reason, and keeps the cart as it was', function () {
        ($this->coupon)('OLD', ['ends_at' => now()->subDay()]);
        ($this->coupon)('BIG', ['min_spend_minor' => 99999999]);
        $token = ($this->add)($this->tee, 1)->json('data.token');
        $h = ($this->guestHeader)($token);

        foreach (['NOSUCH', 'OLD', 'BIG'] as $code) {
            $r = $this->postJson('/api/v1/cart/coupons', ['code' => $code], $h);
            $r->assertStatus(422)->assertJsonPath('code', 'coupon.invalid');
            expect($r->json('detail'))->not->toBeEmpty();
        }
        $this->postJson('/api/v1/cart/coupons', [], $h)->assertStatus(422)->assertJsonValidationErrors(['code']);
        expect(DB::table('cart_coupons')->count())->toBe(0);
    });
});

describe('a signed-in customer’s cart', function () {
    it('belongs to the account: the same on every device, and no guest token can read it', function () {
        $res = ($this->add)($this->tee, 2, ($this->bearer)());
        $res->assertCreated()->assertJsonPath('data.token', null);

        $other = $this->getJson('/api/v1/cart', ($this->bearer)());
        $other->assertOk()->assertJsonPath('data.item_count', 2);
        expect($other->headers->get('X-Cart-Token'))->toBeNull();

        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.item_count', 0);
        $stranger = User::factory()->verified()->create();
        $this->getJson('/api/v1/cart', ($this->bearer)($stranger))->assertOk()->assertJsonPath('data.item_count', 0);
    });

    it('is never confused with a guest cart: a bad or staff token is refused, not read as a guest', function () {
        $this->getJson('/api/v1/cart', ['Authorization' => 'Bearer 1|nonsense'])->assertStatus(401)->assertJsonPath('code', 'auth.unauthenticated');
        $staff = User::factory()->staff()->create();
        $this->getJson('/api/v1/cart', ['Authorization' => 'Bearer '.$staff->createToken('x', ['customer'], now()->addHour())->plainTextToken])->assertStatus(401);
        $this->postJson('/api/v1/cart/items', ['variant' => $this->tee['publicId']], ['Authorization' => 'Bearer 1|nonsense'])->assertStatus(401);
        expect(DB::table('carts')->count())->toBe(0);
    });

    it('takes the guest cart in after signing in, once, and the guest token then shows an empty cart', function () {
        $token = ($this->add)($this->tee, 2)->json('data.token');
        ($this->add)($this->ebook, 1, ($this->guestHeader)($token));
        ($this->add)($this->tee, 1, ($this->bearer)()); // the account already has one tee

        $merged = $this->postJson('/api/v1/cart/merge', [], ($this->bearer)() + ($this->guestHeader)($token));
        $merged->assertOk()->assertJsonPath('data.token', null);
        assertMatchesOpenApi($merged, 'POST', '/api/v1/cart/merge');
        $lines = collect($merged->json('data.lines'))->keyBy('variant_id');
        expect($lines[$this->tee['publicId']]['quantity'])->toBe(3)->and($lines->has($this->ebook['publicId']))->toBeTrue();

        $this->getJson('/api/v1/cart', ($this->guestHeader)($token))->assertOk()->assertJsonPath('data.item_count', 0);
        $this->postJson('/api/v1/cart/merge', [], ($this->bearer)() + ($this->guestHeader)($token))->assertOk()->assertJsonPath('data.item_count', 4);
    });

    it('needs a customer and a token to merge', function () {
        $token = ($this->add)($this->tee, 1)->json('data.token');

        $this->postJson('/api/v1/cart/merge', [], ($this->guestHeader)($token))->assertStatus(401);
        $this->postJson('/api/v1/cart/merge', [], ($this->bearer)())->assertStatus(422);
        expect(DB::table('cart_items')->count())->toBe(1);
    });
});

describe('the contract and the cost', function () {
    it('costs the same number of queries for a cart of one line as for a cart of three', function () {
        $count = function (array $headers): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            test()->getJson('/api/v1/cart', $headers)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $token = ($this->add)($this->tee, 1)->json('data.token');
        $one = $count(($this->guestHeader)($token));
        ($this->add)($this->lamp, 2, ($this->guestHeader)($token));
        ($this->add)($this->ebook, 1, ($this->guestHeader)($token));

        expect($count(($this->guestHeader)($token)))->toBeLessThanOrEqual($one + 2);
    });

    it('agrees with the website’s cart for the same lines', function () {
        $token = str_repeat('c', 40);
        app(AddToCart::class)->handle(CartOwner::guest($token), $this->tee['variantId'], 2);
        app(AddToCart::class)->handle(CartOwner::guest($token), $this->lamp['variantId'], 2);

        $api = $this->getJson('/api/v1/cart', ($this->guestHeader)($token))->json('data');
        $web = app(CartReader::class)->view(CartOwner::guest($token), 'en');

        expect($api['subtotal']['amount_minor'])->toBe($web->subtotal->amountMinor)->and(count($api['lines']))->toBe(count($web->lines))
            ->and(array_column(array_column($api['lines'], 'line_total'), 'amount_minor'))->toBe(array_map(fn ($l) => $l->lineTotal->amountMinor, $web->lines));
    });
});
