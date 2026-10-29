<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Jobs\PurgeExpiredCarts;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\GuestCartToken;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Actions\ScheduleSale;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Contracts\PriceCatalog;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class]);
    $this->actor = Actor::system('t');
    $this->category = app(SaveCategory::class)->handle($this->actor, null, null, [new TranslationInput('en', 'General', 'general'), new TranslationInput('bn', 'সাধারণ')]);

    $this->make = function (string $en, string $bn, string $sku, int $taka, int $stock = 10, ProductType $type = ProductType::Physical, int $min = 1, ?int $max = null, int $step = 1): array {
        $product = app(SaveProduct::class)->handle($this->actor, null, new ProductData($type, [new TranslationInput('en', $en), new TranslationInput('bn', $bn)], categoryIds: [$this->category->id],
            defaultVariant: new VariantData($sku, trackInventory: $type === ProductType::Physical, minQty: $min, maxQty: $max, qtyStep: $step)));
        $variantId = (int) $product->variants()->value('id');
        app(SetRegularPrice::class)->handle($this->actor, $variantId, Money::of($taka * 100));
        if ($type === ProductType::Physical) {
            DB::table('stock_levels')->insert(['variant_id' => $variantId, 'location_id' => (int) DB::table('stock_locations')->value('id'), 'on_hand' => $stock, 'reserved' => 0, 'updated_at' => now()]);
        }
        app(TransitionProduct::class)->publish($this->actor, $product);

        return ['product' => $product, 'variantId' => $variantId, 'publicId' => (string) DB::table('product_variants')->where('id', $variantId)->value('public_id')];
    };

    $this->tee = ($this->make)('Cotton Tee', 'সুতির টি-শার্ট', 'TEE-1', 650, 10);
    $this->lamp = ($this->make)('Brass Lamp', 'পিতলের ল্যাম্প', 'LAMP-1', 2300, 20, min: 2, max: 6, step: 2);
    $this->ebook = ($this->make)('Cooking e-book', 'রান্নার ই-বুক', 'EBOOK-1', 350, 0, ProductType::Digital);

    $this->add = fn ($v, string $qty = '1', ?string $locale = 'en') => $this->post("/{$locale}/cart/items", ['variant' => $v['publicId'], 'quantity' => $qty]);
});

describe('guest cart', function () {
    it('keeps a guest cart behind a random cookie and stores only its hash', function () {
        $response = ($this->add)($this->tee, '2')->assertRedirect('/en/cart');

        $cookie = $response->getCookie(GuestCartToken::COOKIE);
        $token = (string) $cookie?->getValue();
        expect($token)->toMatch('/^[a-f0-9]{40}$/')->and($cookie?->isHttpOnly())->toBeTrue()->and($cookie?->getSameSite())->toBe('lax');

        $cart = Cart::firstOrFail();
        expect($cart->guest_token_hash)->toBe(hash('sha256', $token))->and($cart->user_id)->toBeNull()
            ->and(DB::table('carts')->where('guest_token_hash', $token)->exists())->toBeFalse()   // the token itself is never stored
            ->and(CartItem::firstOrFail()->quantity)->toBe(2);
    });

    it('adds to an existing line instead of creating a second one', function () {
        $first = ($this->add)($this->tee, '2');
        $token = (string) $first->getCookie(GuestCartToken::COOKIE)?->getValue();

        $this->withCookie(GuestCartToken::COOKIE, $token)->post('/en/cart/items', ['variant' => $this->tee['publicId'], 'quantity' => '3'])->assertRedirect();

        expect(CartItem::count())->toBe(1)->and(CartItem::firstOrFail()->quantity)->toBe(5)->and(Cart::count())->toBe(1);
    });

    it('shows the cart with live prices, in both languages', function () {
        $r = ($this->add)($this->tee, '2');
        $token = (string) $r->getCookie(GuestCartToken::COOKIE)?->getValue();

        $this->withCookie(GuestCartToken::COOKIE, $token)->get('/en/cart')->assertOk()->assertSee('Cotton Tee')->assertSee('650')->assertSee('1,300')->assertSee('Subtotal');
        $this->withCookie(GuestCartToken::COOKIE, $token)->get('/bn/cart')->assertOk()->assertSee('সুতির টি-শার্ট')->assertSee('উপমোট');
    });

    it('never trusts a price or total sent by the browser', function () {
        ($this->add)($this->tee, '1');
        $this->post('/en/cart/items', ['variant' => $this->tee['publicId'], 'quantity' => '1', 'price' => '1', 'unit_price_minor' => 1, 'total' => '0'])->assertRedirect();

        $view = app(CartReader::class)->view(CartOwner::guest(null), 'en');
        expect(CartItem::firstOrFail()->added_price_minor)->toBe(65000)->and($view->isEmpty())->toBeTrue();
    });

    it('keeps one guest\'s cart away from another\'s', function () {
        $a = (string) ($this->add)($this->tee, '1')->getCookie(GuestCartToken::COOKIE)?->getValue();
        $this->flushSession();
        $b = (string) ($this->add)($this->lamp, '2')->getCookie(GuestCartToken::COOKIE)?->getValue();

        expect($a)->not->toBe($b)->and(Cart::count())->toBe(2);
        $this->withCookie(GuestCartToken::COOKIE, $a)->get('/en/cart')->assertSee('Cotton Tee')->assertDontSee('Brass Lamp');
        $this->withCookie(GuestCartToken::COOKIE, $b)->get('/en/cart')->assertSee('Brass Lamp')->assertDontSee('Cotton Tee');
    });

    it('ignores a forged or malformed cart cookie', function () {
        $this->withCookie(GuestCartToken::COOKIE, str_repeat('a', 40))->get('/en/cart')->assertOk()->assertSee('Your cart is empty');
        $this->withCookie(GuestCartToken::COOKIE, '../../etc/passwd')->get('/en/cart')->assertOk()->assertSee('Your cart is empty');
    });
});

describe('quantity rules', function () {
    it('enforces minimum, step, maximum and stock, with plain messages and no change to the cart', function () {
        ($this->add)($this->lamp, '1')->assertSessionHasErrors(['cart' => 'The smallest quantity you can order is 2.']);
        ($this->add)($this->lamp, '3')->assertSessionHasErrors(['cart' => 'This item is sold in steps of 2, starting from 2.']);
        ($this->add)($this->lamp, '8')->assertSessionHasErrors(['cart' => 'You can order at most 6 of this item.']);
        ($this->add)($this->tee, '11')->assertSessionHasErrors(['cart' => 'Sorry, only 10 left in stock.']);
        ($this->add)($this->tee, '0')->assertSessionHasErrors('cart');
        ($this->add)($this->tee, 'abc')->assertSessionHasErrors('cart');
        ($this->add)($this->tee, '-3')->assertSessionHasErrors('cart');
        ($this->add)($this->tee, '1.5')->assertSessionHasErrors('cart');

        expect(CartItem::count())->toBe(0);
        ($this->add)($this->lamp, '4')->assertSessionDoesntHaveErrors();
        expect(CartItem::firstOrFail()->quantity)->toBe(4);
    });

    it('also holds when quantities are added up across several adds', function () {
        $token = (string) ($this->add)($this->tee, '8')->getCookie(GuestCartToken::COOKIE)?->getValue();

        $this->withCookie(GuestCartToken::COOKIE, $token)->post('/en/cart/items', ['variant' => $this->tee['publicId'], 'quantity' => '3'])->assertSessionHasErrors('cart'); // 8 + 3 > 10 in stock

        expect(CartItem::firstOrFail()->quantity)->toBe(8);
    });

    it('does not limit digital products by stock', function () {
        ($this->add)($this->ebook, '5')->assertSessionDoesntHaveErrors();

        expect(CartItem::firstOrFail()->quantity)->toBe(5);
    });

    it('reads Bangla digits', function () {
        ($this->add)($this->tee, '৩')->assertSessionDoesntHaveErrors();

        expect(CartItem::firstOrFail()->quantity)->toBe(3);
    });

    it('refuses things that cannot be bought', function () {
        $this->post('/en/cart/items', ['variant' => str_repeat('0', 26), 'quantity' => '1'])->assertSessionHasErrors(['cart' => 'Sorry, this item cannot be bought right now.']);
        app(TransitionProduct::class)->unpublish($this->actor, $this->tee['product']);
        ($this->add)($this->tee, '1')->assertSessionHasErrors(['cart' => 'Sorry, this item cannot be bought right now.']);

        expect(CartItem::count())->toBe(0);
    });

    it('caps the number of different items in a cart', function () {
        $token = null;
        foreach (range(1, 50) as $i) {
            $p = ($this->make)("Item {$i}", "পণ্য {$i}", "BULK-{$i}", 100, 5);
            $r = $token === null ? ($this->add)($p, '1') : $this->withCookie(GuestCartToken::COOKIE, $token)->post('/en/cart/items', ['variant' => $p['publicId'], 'quantity' => '1']);
            $token ??= (string) $r->getCookie(GuestCartToken::COOKIE)?->getValue();
        }
        $extra = ($this->make)('One too many', 'আরেকটি', 'BULK-51', 100, 5);

        $this->withCookie(GuestCartToken::COOKIE, $token)->post('/en/cart/items', ['variant' => $extra['publicId'], 'quantity' => '1'])->assertSessionHasErrors('cart');
        expect(CartItem::count())->toBe(50);
    });
});

describe('changing the cart', function () {
    it('updates, removes and sets zero', function () {
        $token = (string) ($this->add)($this->tee, '2')->getCookie(GuestCartToken::COOKIE)?->getValue();
        $with = fn () => $this->withCookie(GuestCartToken::COOKIE, $token);

        $with()->patch("/en/cart/items/{$this->tee['publicId']}", ['quantity' => '5'])->assertRedirect('/en/cart');
        expect(CartItem::firstOrFail()->quantity)->toBe(5);

        $with()->patch("/en/cart/items/{$this->tee['publicId']}", ['quantity' => '99'])->assertSessionHasErrors('cart');
        expect(CartItem::firstOrFail()->quantity)->toBe(5);

        $with()->patch("/en/cart/items/{$this->tee['publicId']}", ['quantity' => '0'])->assertRedirect();
        expect(CartItem::count())->toBe(0);

        ($this->add)($this->lamp, '2');
        $with()->post('/en/cart/items', ['variant' => $this->lamp['publicId'], 'quantity' => '2']);
        $with()->delete("/en/cart/items/{$this->lamp['publicId']}")->assertRedirect('/en/cart');
        expect(CartItem::count())->toBe(0);
    });

    it('cannot change a line that is not in the cart', function () {
        $token = (string) ($this->add)($this->tee, '1')->getCookie(GuestCartToken::COOKIE)?->getValue();

        $this->withCookie(GuestCartToken::COOKIE, $token)->patch("/en/cart/items/{$this->lamp['publicId']}", ['quantity' => '2'])->assertSessionHasErrors(['cart' => 'That item is not in your cart.']);
    });
});

describe('changes since the customer added an item', function () {
    it('shows a price change, and the notice goes away once accepted', function () {
        $token = (string) ($this->add)($this->tee, '2')->getCookie(GuestCartToken::COOKIE)?->getValue();
        app(SetRegularPrice::class)->handle($this->actor, $this->tee['variantId'], Money::of(70000));
        $page = fn () => $this->withCookie(GuestCartToken::COOKIE, $token);

        $page()->get('/en/cart')->assertSee('Some prices have changed')->assertSee('Was ')->assertSee('700')->assertSee('1,400');
        $page()->post('/en/cart/acknowledge')->assertRedirect('/en/cart');
        $page()->get('/en/cart')->assertDontSee('Some prices have changed');
        expect(CartItem::firstOrFail()->added_price_minor)->toBe(70000);
    });

    it('applies a sale to the cart immediately', function () {
        $token = (string) ($this->add)($this->tee, '1')->getCookie(GuestCartToken::COOKIE)?->getValue();
        app(ScheduleSale::class)->handle($this->actor, $this->tee['variantId'], Money::of(50000), now('Asia/Dhaka')->subHour()->format('Y-m-d H:i'), now('Asia/Dhaka')->addDay()->format('Y-m-d H:i'));

        $this->withCookie(GuestCartToken::COOKIE, $token)->get('/en/cart')->assertSee('500')->assertSee('Some prices have changed');
    });

    it('warns when stock has run down and when an item is no longer sold, and leaves unavailable items out of the subtotal', function () {
        $token = (string) ($this->add)($this->tee, '6')->getCookie(GuestCartToken::COOKIE)?->getValue();
        $this->withCookie(GuestCartToken::COOKIE, $token)->post('/en/cart/items', ['variant' => $this->lamp['publicId'], 'quantity' => '2']);

        app(AdjustStock::class)->handle($this->actor, $this->tee['variantId'], 'main', -4, 'write_off', 'test'); // 6 left
        app(AdjustStock::class)->handle($this->actor, $this->tee['variantId'], 'main', -3, 'write_off', 'test'); // 3 left
        app(TransitionProduct::class)->unpublish($this->actor, $this->lamp['product']);

        $view = app(CartReader::class)->view(CartOwner::guest($token), 'en');
        expect(collect($view->lines)->pluck('problem', 'sku')->all())->toBe(['TEE-1' => 'insufficient_stock', 'LAMP-1' => 'unavailable'])
            ->and($view->subtotal->amountMinor)->toBe(6 * 65000);
        $this->withCookie(GuestCartToken::COOKIE, $token)->get('/en/cart')->assertSee('Only 3 left')->assertSee('can no longer be bought');
    });
});

describe('signing in', function () {
    it('merges the guest cart into the customer\'s cart and retires the cookie', function () {
        $user = User::factory()->create(['email' => 'amina@example.com']);
        $this->actingAs($user, 'web')->post('/en/cart/items', ['variant' => $this->tee['publicId'], 'quantity' => '2']);
        auth('web')->logout();
        $this->flushSession();

        $token = (string) ($this->add)($this->tee, '3')->getCookie(GuestCartToken::COOKIE)?->getValue();
        $this->withCookie(GuestCartToken::COOKIE, $token)->post('/en/cart/items', ['variant' => $this->lamp['publicId'], 'quantity' => '2']);
        expect(Cart::count())->toBe(2);

        $response = $this->withCookie(GuestCartToken::COOKIE, $token)->post('/en/login', ['identifier' => 'amina@example.com', 'password' => GOOD_PASSWORD]);
        $response->assertRedirect('/en/account');

        $mine = Cart::where('user_id', $user->id)->firstOrFail();
        expect(CartItem::where('cart_id', $mine->id)->pluck('quantity', 'variant_id')->all())->toBe([$this->tee['variantId'] => 5, $this->lamp['variantId'] => 2])
            ->and(Cart::whereNull('user_id')->count())->toBe(0)
            ->and($response->getCookie(GuestCartToken::COOKIE)?->getExpiresTime())->toBeLessThan(time()); // cookie is cleared
    });

    it('gives a customer who had no cart the guest cart', function () {
        $user = User::factory()->create(['email' => 'rahim@example.com']);
        $token = (string) ($this->add)($this->tee, '4')->getCookie(GuestCartToken::COOKIE)?->getValue();

        $this->withCookie(GuestCartToken::COOKIE, $token)->post('/en/login', ['identifier' => 'rahim@example.com', 'password' => GOOD_PASSWORD])->assertRedirect();

        expect(CartItem::where('cart_id', Cart::where('user_id', $user->id)->value('id'))->value('quantity'))->toBe(4);
    });

    it('caps merged quantities at the per-line limit', function () {
        $user = User::factory()->create(['email' => 'cap@example.com']);
        $bulk = ($this->make)('Bulk pens', 'কলম', 'PEN-1', 10, 500);
        $cart = Cart::create(['user_id' => $user->id, 'expires_at' => now()->addDays(90)]);
        CartItem::create(['cart_id' => $cart->id, 'variant_id' => $bulk['variantId'], 'quantity' => 80, 'added_price_minor' => 1000]);
        $guest = Cart::create(['guest_token_hash' => hash('sha256', str_repeat('b', 40)), 'expires_at' => now()->addDays(30)]);
        CartItem::create(['cart_id' => $guest->id, 'variant_id' => $bulk['variantId'], 'quantity' => 60, 'added_price_minor' => 1000]);

        $this->withCookie(GuestCartToken::COOKIE, str_repeat('b', 40))->post('/en/login', ['identifier' => 'cap@example.com', 'password' => GOOD_PASSWORD]);

        expect((int) CartItem::where('cart_id', $cart->id)->value('quantity'))->toBe(100);
    });
});

describe('housekeeping', function () {
    it('shows the cart size in the header, in both languages', function () {
        $token = (string) ($this->add)($this->tee, '2')->getCookie(GuestCartToken::COOKIE)?->getValue();

        $this->get('/en/shop')->assertSee('aria-label="Cart, empty"', false);
        $this->withCookie(GuestCartToken::COOKIE, $token)->get('/en/shop')->assertSee('aria-label="Cart, 2 items"', false);
        $this->withCookie(GuestCartToken::COOKIE, $token)->get('/bn/shop')->assertSee('aria-label="কার্ট, 2 টি পণ্য"', false);
    });

    it('lets the product page add to the cart, and says so when out of stock', function () {
        $this->get('/en/p/cotton-tee')->assertOk()->assertSee('name="variant"', false)->assertSee('value="'.$this->tee['publicId'].'"', false)->assertSee('Add to cart');
        $this->get('/en/p/brass-lamp')->assertOk()->assertSee('Minimum 2, in steps of 2.')->assertSee('value="2"', false);

        app(AdjustStock::class)->handle($this->actor, $this->tee['variantId'], 'main', -10, 'write_off', 'test');
        $this->get('/en/p/cotton-tee')->assertOk()->assertDontSee('name="variant"', false)->assertSee('Out of stock');
    });

    it('deletes expired carts only, and pushes expiry out when a cart is used', function () {
        $token = (string) ($this->add)($this->tee, '1')->getCookie(GuestCartToken::COOKIE)?->getValue();
        $cart = Cart::firstOrFail();
        expect($cart->expires_at->greaterThan(now()->addDays(29)))->toBeTrue();

        $old = Cart::create(['guest_token_hash' => hash('sha256', 'x'), 'expires_at' => now()->subDay()]);
        CartItem::create(['cart_id' => $old->id, 'variant_id' => $this->tee['variantId'], 'quantity' => 1, 'added_price_minor' => 1]);
        $cart->forceFill(['expires_at' => now()->addHour()])->save();
        $this->withCookie(GuestCartToken::COOKIE, $token)->post('/en/cart/items', ['variant' => $this->tee['publicId'], 'quantity' => '1']);
        expect($cart->fresh()->expires_at->greaterThan(now()->addDays(29)))->toBeTrue();

        (new PurgeExpiredCarts)->handle();

        expect(Cart::pluck('id')->all())->toBe([$cart->id])->and(CartItem::where('cart_id', $old->id)->count())->toBe(0);
    });

    it('builds the cart page in a fixed number of queries however many lines it has', function () {
        $token = null;
        foreach (range(1, 12) as $i) {
            $p = ($this->make)("Line {$i}", "লাইন {$i}", "LINE-{$i}", 100 + $i, 5);
            $r = $token === null ? ($this->add)($p, '1') : $this->withCookie(GuestCartToken::COOKIE, $token)->post('/en/cart/items', ['variant' => $p['publicId'], 'quantity' => '1']);
            $token ??= (string) $r->getCookie(GuestCartToken::COOKIE)?->getValue();
        }

        DB::enableQueryLog();
        $this->withCookie(GuestCartToken::COOKIE, $token)->get('/en/cart')->assertOk()->assertSee('Line 12');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($count)->toBeLessThan(30);
    });
});

it('quotes many variants exactly like one at a time, including sales and tax', function () {
    $standard = DB::table('tax_classes')->where('code', 'standard')->value('id');
    DB::table('tax_rates')->insert(['tax_class_id' => $standard, 'jurisdiction' => 'BD', 'rate_bp' => 1500, 'valid_from' => '2020-01-01', 'valid_to' => '9999-12-31', 'created_at' => now(), 'updated_at' => now()]);
    app(Settings::class)->set('tax.enabled', true);
    app(Settings::class)->set('tax.prices_include_tax', false);
    app(ScheduleSale::class)->handle($this->actor, $this->tee['variantId'], Money::of(50000), now('Asia/Dhaka')->subHour()->format('Y-m-d H:i'), now('Asia/Dhaka')->addDay()->format('Y-m-d H:i'));

    $prices = app(PriceCatalog::class);
    $map = [$this->tee['variantId'] => 'standard', $this->lamp['variantId'] => 'standard', $this->ebook['variantId'] => 'zero_rated', 999999 => 'standard'];
    $many = $prices->quoteMany($map);

    expect(array_keys($many))->toBe([$this->tee['variantId'], $this->lamp['variantId'], $this->ebook['variantId']]);
    foreach ($many as $id => $q) {
        expect($q)->toEqual($prices->quote($id, $map[$id]));
    }
});

describe('discount codes', function () {
    beforeEach(function () {
        $this->code = fn (string $code, array $over = []) => DB::table('coupons')->insertGetId($over + ['public_id' => strtolower((string) Str::ulid()), 'code' => $code, 'type' => 'percent', 'value' => 1000, 'scope' => 'all', 'is_active' => true, 'min_spend_minor' => 0, 'used_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->token = (string) ($this->add)($this->tee, '2')->getCookie(GuestCartToken::COOKIE)?->getValue();   // ৳1,300
        $this->apply = fn (string $typed, string $locale = 'en') => $this->withCookie(GuestCartToken::COOKIE, $this->token)->post("/{$locale}/cart/coupons", ['code' => $typed]);
        $this->cartPage = fn (string $locale = 'en') => $this->withCookie(GuestCartToken::COOKIE, $this->token)->get("/{$locale}/cart");
    });

    it('applies a code typed in any case and shows the discount and the goods total after it', function () {
        ($this->code)('SAVE10');

        ($this->apply)(' save10 ')->assertRedirect('/en/cart')->assertSessionHasNoErrors();

        ($this->cartPage)()->assertOk()->assertSee('SAVE10')->assertSee('130')->assertSee('1,170')->assertSee('Items after discount');
        expect(DB::table('cart_coupons')->pluck('code')->all())->toBe(['SAVE10']);
    });

    it('explains in the shopper\'s language why a code is refused, and keeps nothing', function () {
        ($this->code)('OLD', ['ends_at' => '2020-01-01 00:00:00']);
        ($this->code)('BIG', ['min_spend_minor' => 999999]);

        ($this->apply)('NOSUCH')->assertSessionHasErrors(['coupon' => 'We do not recognise the code “NOSUCH”.']);
        ($this->apply)('old')->assertSessionHasErrors(['coupon' => 'The code “OLD” has expired.']);
        ($this->apply)('BIG')->assertSessionHasErrors('coupon');
        ($this->apply)('!!', 'bn')->assertSessionHasErrors('coupon');
        expect(DB::table('cart_coupons')->count())->toBe(0);
    });

    it('has Bangla wording for every refusal', function () {
        foreach (['unknown', 'inactive', 'not_started', 'expired', 'exhausted', 'per_customer_limit', 'min_spend', 'not_applicable', 'not_combinable', 'too_many', 'no_discount'] as $reason) {
            expect(trans("pricing::coupons.reasons.{$reason}", [], 'bn'))->not->toBe("pricing::coupons.reasons.{$reason}");
        }
    });

    it('accepts one discount code and one free-delivery code, and no third', function () {
        ($this->code)('SAVE10');
        ($this->code)('FIVE', ['type' => 'fixed', 'value' => 500]);
        ($this->code)('SHIP', ['type' => 'free_shipping', 'value' => 0]);

        ($this->apply)('SAVE10')->assertSessionHasNoErrors();
        ($this->apply)('FIVE')->assertSessionHasErrors('coupon');
        ($this->apply)('SHIP')->assertSessionHasNoErrors();
        expect(DB::table('cart_coupons')->pluck('code')->sort()->values()->all())->toBe(['SAVE10', 'SHIP']);

        $view = app(CartReader::class)->view(CartOwner::guest($this->token), 'en');
        expect($view->freeShipping())->toBeTrue()->and($view->discountTotal()->amountMinor)->toBe(13000)->and($view->goodsTotal()->amountMinor)->toBe(117000);
        ($this->cartPage)()->assertSee('Free delivery');
    });

    it('typing the same code twice is one code', function () {
        ($this->code)('SAVE10');
        ($this->apply)('SAVE10');
        ($this->apply)('save10')->assertSessionHasNoErrors();

        expect(DB::table('cart_coupons')->count())->toBe(1);
    });

    it('removes a code', function () {
        ($this->code)('SAVE10');
        ($this->apply)('SAVE10');

        $this->withCookie(GuestCartToken::COOKIE, $this->token)->delete('/en/cart/coupons/SAVE10')->assertRedirect('/en/cart');

        expect(DB::table('cart_coupons')->count())->toBe(0);
        ($this->cartPage)()->assertDontSee('SAVE10');
    });

    it('stops applying a code that stops working, says so, and does not charge less', function () {
        $id = ($this->code)('SAVE10');
        ($this->apply)('SAVE10');
        DB::table('coupons')->where('id', $id)->update(['is_active' => false]);

        $view = app(CartReader::class)->view(CartOwner::guest($this->token), 'en');
        expect($view->discountTotal()->amountMinor)->toBe(0)->and($view->discounts?->rejected[0]['reason'])->toBe('inactive');
        ($this->cartPage)()->assertSee('no longer works', false)->assertSee('not active');
    });

    it('re-checks the minimum spend when the cart shrinks', function () {
        ($this->code)('BIGSPEND', ['min_spend_minor' => 100000]);
        ($this->apply)('BIGSPEND')->assertSessionHasNoErrors();

        $this->withCookie(GuestCartToken::COOKIE, $this->token)->patch("/en/cart/items/{$this->tee['publicId']}", ['quantity' => '1'])->assertRedirect();

        expect(app(CartReader::class)->view(CartOwner::guest($this->token), 'en')->discounts?->rejected[0]['reason'])->toBe('min_spend');
    });

    it('needs a cart, and a code field with something in it', function () {
        $this->flushSession();
        $this->post('/en/cart/coupons', ['code' => 'SAVE10'])->assertSessionHasErrors('coupon');
        ($this->apply)('')->assertSessionHasErrors('code');
    });

    it('keeps one shopper\'s codes out of another\'s cart', function () {
        ($this->code)('SAVE10');
        ($this->apply)('SAVE10');
        $other = str_repeat('b', 40);
        app(AddToCart::class)->handle(CartOwner::guest($other), $this->lamp['variantId'], 2);

        expect(app(CartReader::class)->view(CartOwner::guest($other), 'en')->discountTotal()->amountMinor)->toBe(0);
    });

    it('counts the queries the same however many codes and lines there are', function () {
        ($this->code)('SAVE10');
        ($this->code)('SHIP', ['type' => 'free_shipping', 'value' => 0]);
        ($this->apply)('SAVE10');
        ($this->apply)('SHIP');
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(CartReader::class)->view(CartOwner::guest($this->token), 'en');

            return count(DB::getQueryLog());
        };
        $before = $count();
        $this->withCookie(GuestCartToken::COOKIE, $this->token)->post('/en/cart/items', ['variant' => $this->lamp['publicId'], 'quantity' => '2']);

        expect($count())->toBe($before);
    });
});
