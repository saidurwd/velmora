<?php

declare(strict_types=1);

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    shopWorld($this);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->amina = User::factory()->create(['email' => 'amina@example.com']);
    $this->bob = User::factory()->create(['email' => 'bob@example.com']);
    $this->service = ($this->make)('Tailoring', 'দর্জি', 'SVC-1', 500, 0, ProductType::Service);
    $this->reorder = fn ($order, ?User $as = null, string $locale = 'en') => $this->actingAs($as ?? $this->amina, 'web')->post("/{$locale}/account/orders/{$order->public_id}/reorder");
    $this->cart = fn (?User $u = null) => CartItem::query()->whereIn('cart_id', Cart::query()->where('user_id', ($u ?? $this->amina)->id)->select('id'))->orderBy('id')->get()->mapWithKeys(fn ($i) => [(int) $i->variant_id => [(int) $i->quantity, (int) $i->added_price_minor]])->all();
});

describe('buy again', function () {
    it('is for signed-in customers', function () {
        $order = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 2]);

        $this->post("/en/account/orders/{$order->public_id}/reorder")->assertRedirect('/en/login');
        expect(Cart::query()->count())->toBe(0);
    });

    it('puts the same items in the cart at today\'s price, not the price they were bought at', function () {
        $order = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 2, $this->ebook['variantId'] => 1], unitMinor: 10000);   // bought at 100 taka
        ($this->setPrice)($this->tee, 800);                                                                                               // costs 800 today

        ($this->reorder)($order)->assertRedirect("/en/account/orders/{$order->public_id}")->assertSessionHas('reorder');

        expect(($this->cart)())->toBe([$this->tee['variantId'] => [2, 80000], $this->ebook['variantId'] => [1, 35000]]);
    });

    it('tells the customer what went in, and takes them to the cart', function () {
        $order = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 2]);
        ($this->reorder)($order);

        $html = $this->actingAs($this->amina, 'web')->get("/en/account/orders/{$order->public_id}")->assertOk()->getContent();
        expect($html)->toContain('What went into your cart')->and($html)->toContain('× 2 added.')->and($html)->toContain('Buy again')->and($html)->toContain(route('cart.show', ['locale' => 'en']));
    });

    it('buys no one else\'s order again', function () {
        $order = ($this->orderFor)($this->bob->id, [$this->tee['variantId'] => 2]);

        ($this->reorder)($order)->assertNotFound();
        ($this->reorder)($order, $this->amina)->assertNotFound();
        expect(Cart::query()->count())->toBe(0)->and(($this->cart)($this->amina))->toBe([]);
    });

    it('is "not found" for an order that does not exist', function () {
        $this->actingAs($this->amina, 'web')->post('/en/account/orders/'.str_repeat('0', 26).'/reorder')->assertNotFound();
    });

    it('skips what is sold out and still adds the rest, saying why', function () {
        $order = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 2, $this->ebook['variantId'] => 1]);
        ($this->setStock)($this->tee, 0);

        ($this->reorder)($order);

        expect(($this->cart)())->toBe([$this->ebook['variantId'] => [1, 35000]]);
        $html = $this->actingAs($this->amina, 'web')->get("/en/account/orders/{$order->public_id}")->getContent();
        expect($html)->toContain('was not added.')->and($html)->toContain('only 0 left in stock');
    });

    it('adds the part that is available when there is not enough', function () {
        $order = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 5]);
        ($this->setStock)($this->tee, 3);

        ($this->reorder)($order);

        expect(($this->cart)())->toBe([$this->tee['variantId'] => [3, 65000]]);
        $this->actingAs($this->amina, 'web')->get("/en/account/orders/{$order->public_id}")->assertSee('only 3 of 5 added.', false);
    });

    it('counts what is already in the cart, and respects the steps an item is sold in', function () {
        $order = ($this->orderFor)($this->amina->id, [$this->lamp['variantId'] => 6]);   // a lamp is sold in twos, 2 to 6
        ($this->setStock)($this->lamp, 5);
        $this->actingAs($this->amina, 'web')->post('/en/cart/items', ['variant' => $this->lamp['publicId'], 'quantity' => '2'])->assertSessionHasNoErrors();

        ($this->reorder)($order);

        expect(($this->cart)())->toBe([$this->lamp['variantId'] => [4, 230000]]);   // 5 in stock: 2 already there + 2 more (a third pair does not fit)
    });

    it('skips a service, which is booked rather than bought again, and what is no longer sold', function () {
        $gone = ($this->make)('Old Shirt', 'পুরোনো শার্ট', 'OLD-1', 300, 5);
        $order = ($this->orderFor)($this->amina->id, [$this->service['variantId'] => 1, $gone['variantId'] => 1, $this->tee['variantId'] => 1]);
        app(TransitionProduct::class)->archive($this->actor, $gone['product']);

        ($this->reorder)($order);

        expect(($this->cart)())->toBe([$this->tee['variantId'] => [1, 65000]]);
        $html = $this->actingAs($this->amina, 'web')->get("/en/account/orders/{$order->public_id}")->getContent();
        expect($html)->toContain('Choose a time first')->and($html)->toContain('cannot be bought right now');
    });

    it('buys nothing and holds no stock: it only fills the cart', function () {
        $order = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 2]);
        $ordersBefore = DB::table('orders')->count();
        $reservedBefore = DB::table('stock_levels')->sum('reserved');

        ($this->reorder)($order);

        expect(DB::table('orders')->count())->toBe($ordersBefore)->and((int) DB::table('stock_levels')->sum('reserved'))->toBe((int) $reservedBefore);
    });

    it('doing it twice raises the quantity, as adding to a cart twice would', function () {
        $order = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 2]);
        ($this->reorder)($order);
        ($this->reorder)($order);

        expect(($this->cart)())->toBe([$this->tee['variantId'] => [4, 65000]]);
    });

    it('is shown in Bangla with no missing words', function () {
        $order = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 2], locale: 'bn');
        ($this->reorder)($order, null, 'bn');

        $html = $this->actingAs($this->amina, 'web')->get("/bn/account/orders/{$order->public_id}")->assertOk()->getContent();
        expect($html)->toContain('আবার কিনুন')->and($html)->toContain('আপনার কার্টে যা গেল')->and($html)->not->toContain('orders::')->and($html)->not->toContain('cart::');
    });
});
