<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Services\GuestCartToken;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Customers\Contracts\AddressBook;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Listeners\SendOrderConfirmation;
use App\Modules\Orders\Mail\OrderPlacedMail;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, GeographySeeder::class, ShippingSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $actor = Actor::system('t');
    $category = app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', 'General')]);

    $this->make = function (string $sku, string $en, string $bn, int $taka, int $stock = 10, ProductType $type = ProductType::Physical) use ($actor, $category): int {
        $product = app(SaveProduct::class)->handle($actor, null, new ProductData($type, [new TranslationInput('en', $en), new TranslationInput('bn', $bn)], categoryIds: [$category->id],
            defaultVariant: new VariantData($sku, trackInventory: $type === ProductType::Physical, weightG: $type === ProductType::Physical ? 300 : null)));
        $variantId = (int) $product->variants()->value('id');
        app(SetRegularPrice::class)->handle($actor, $variantId, Money::of($taka * 100));
        if ($type === ProductType::Physical) {
            DB::table('stock_levels')->insert(['variant_id' => $variantId, 'location_id' => (int) DB::table('stock_locations')->value('id'), 'on_hand' => $stock, 'reserved' => 0, 'updated_at' => now()]);
        }
        app(TransitionProduct::class)->publish($actor, $product);

        return $variantId;
    };
    $this->tee = ($this->make)('TEE-1', 'Cotton Tee', 'সুতির টি-শার্ট', 650, 5);
    $this->ebook = ($this->make)('EBOOK-1', 'E-book', 'ই-বুক', 200, 0, ProductType::Digital);

    $this->dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->sylhet = (int) DB::table('districts')->where('name_en', 'Sylhet')->value('id');
    $this->division = (int) DB::table('districts')->where('id', $this->dhaka)->value('division_id');
    $this->upazila = (int) DB::table('upazilas')->where('district_id', $this->dhaka)->value('id');
    $this->token = str_repeat('f', 40);
    $this->owner = CartOwner::guest($this->token);
    $this->cart = fn (int $variant, int $qty = 1) => app(AddToCart::class)->handle($this->owner, $variant, $qty);
    $this->as = fn () => $this->withCookie(GuestCartToken::COOKIE, $this->token);
    $this->form = fn (array $over = []) => array_replace([
        'recipient' => 'Rahim Uddin', 'phone' => '০১৭১২৩৪৫৬৭৮', 'division_id' => $this->division, 'district_id' => $this->dhaka, 'upazila_id' => $this->upazila,
        'address_line' => 'House 5, Road 2', 'area' => 'Dhanmondi', 'postal_code' => '1205', 'email' => 'Rahim@Example.com',
    ], $over);
    $this->goToReview = function (string $locale = 'en', array $over = []) {
        ($this->as)()->post("/{$locale}/checkout", ($this->form)($over))->assertRedirect("/{$locale}/checkout/review");
    };
    $this->placeForm = function (string $locale = 'en') {
        $html = ($this->as)()->get("/{$locale}/checkout/review")->assertOk()->getContent();
        preg_match('/name="place_token" value="([^"]+)"/', $html, $t);
        preg_match('/name="expected_total" value="(\d+)"/', $html, $e);

        return ['place_token' => $t[1], 'expected_total' => $e[1], 'shipping' => 'standard', 'payment' => 'cod'];
    };
});

describe('guest checkout', function () {
    it('goes from the cart to a confirmation page, in English', function () {
        ($this->cart)($this->tee, 2);

        ($this->as)()->get('/en/checkout')->assertOk()->assertSee('Checkout')->assertSee('Deliver to')->assertSee('Cotton Tee');
        ($this->goToReview)();

        $review = ($this->as)()->get('/en/checkout/review')->assertOk()->assertSee('Review your order')->assertSee('Standard delivery')->assertSee('Cash on delivery')->assertSee('Rahim Uddin')->assertSee('rahim@example.com')->assertSee('1,360');   // ৳1,300 + ৳60
        $form = ($this->placeForm)();
        $response = ($this->as)()->post('/en/checkout/place', $form)->assertRedirect();

        $order = Order::firstOrFail();
        expect($order->contact_phone)->toBe('+8801712345678')->and($order->grand_total_minor)->toBe(136000)->and($order->contact_email)->toBe('rahim@example.com');
        $thanks = $response->headers->get('Location');
        expect($thanks)->toContain('/en/orders/'.$order->public_id.'/thanks')->and($thanks)->toContain('signature=');

        $this->get($thanks)->assertOk()->assertSee($order->order_number)->assertSee('Thank you')->assertSee('Cotton Tee')->assertSee('1,360')->assertSee('pay in cash')->assertSee('+88017****5678')->assertDontSee('+8801712345678');
        expect(DB::table('cart_items')->count())->toBe(0);
    });

    it('goes through the same steps in Bangla, with Bangla digits accepted', function () {
        ($this->cart)($this->tee, 1);

        ($this->as)()->get('/bn/checkout')->assertOk()->assertSee('চেকআউট')->assertSee('সুতির টি-শার্ট');
        ($this->goToReview)('bn');
        ($this->as)()->get('/bn/checkout/review')->assertOk()->assertSee('অর্ডার যাচাই করুন')->assertSee('ক্যাশ অন ডেলিভারি')->assertSee('সাধারণ ডেলিভারি');
        $response = ($this->as)()->post('/bn/checkout/place', ($this->placeForm)('bn'))->assertRedirect();

        $this->get($response->headers->get('Location'))->assertOk()->assertSee('ধন্যবাদ')->assertSee(Order::firstOrFail()->order_number);
        expect(Order::firstOrFail()->locale)->toBe('bn');
    });

    it('sends the shopper back to the cart when it is empty or has a problem', function () {
        ($this->as)()->get('/en/checkout')->assertRedirect('/en/cart');

        ($this->cart)($this->tee, 1);
        DB::table('stock_levels')->where('variant_id', $this->tee)->update(['on_hand' => 0]);
        ($this->as)()->get('/en/checkout')->assertRedirect('/en/cart');
        ($this->as)()->post('/en/checkout', ($this->form)())->assertRedirect('/en/cart');
    });

    it('sends the shopper to the details step first, and never lets the review page be reached by guessing', function () {
        ($this->cart)($this->tee, 1);

        ($this->as)()->get('/en/checkout/review')->assertRedirect('/en/checkout');
    });

    it('explains address mistakes beside the field, in the shopper\'s language', function () {
        ($this->cart)($this->tee, 1);

        ($this->as)()->post('/en/checkout', ($this->form)(['phone' => '12345']))->assertSessionHasErrors('phone');
        ($this->as)()->post('/en/checkout', ($this->form)(['address_line' => '']))->assertSessionHasErrors('address_line');
        ($this->as)()->post('/en/checkout', ($this->form)(['email' => 'not-an-email']))->assertSessionHasErrors('email');
        ($this->as)()->post('/en/checkout', ($this->form)(['district_id' => $this->sylhet]))->assertSessionHasErrors('upazila_id');   // upazila of Dhaka is not in Sylhet
        ($this->as)()->post('/bn/checkout', ($this->form)(['postal_code' => '12']))->assertSessionHasErrors('postal_code');
        expect(session('checkout'))->toBeNull();
    });

    it('lets the shopper pick another delivery option and shows the new total before ordering', function () {
        ($this->cart)($this->tee, 1);
        $now = now();
        $express = DB::table('shipping_methods')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'code' => 'express', 'cod_available' => true, 'cod_fee_minor' => 2000, 'free_over_minor' => null, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('shipping_method_translations')->insert([['method_id' => $express, 'locale' => 'en', 'name' => 'Express delivery', 'delivery_estimate' => 'Next day'], ['method_id' => $express, 'locale' => 'bn', 'name' => 'এক্সপ্রেস ডেলিভারি', 'delivery_estimate' => 'পরদিন']]);
        foreach (DB::table('shipping_zones')->pluck('id') as $zone) {
            DB::table('shipping_rates')->insert(['zone_id' => $zone, 'method_id' => $express, 'max_weight_g' => null, 'price_minor' => 15000, 'created_at' => $now, 'updated_at' => $now]);
        }
        ($this->goToReview)();

        ($this->as)()->get('/en/checkout/review')->assertSee('Express delivery')->assertSee('Standard delivery')->assertSee('710');   // ৳650 + ৳60
        ($this->as)()->post('/en/checkout/review', ['shipping' => 'express', 'payment' => 'cod'])->assertRedirect('/en/checkout/review');
        ($this->as)()->get('/en/checkout/review')->assertSee('20.00')->assertSee('820.00')->assertSee('Update totals');   // ৳650 + ৳150 + ৳20 COD fee

        $form = ($this->placeForm)();
        $form['shipping'] = 'express';
        ($this->as)()->post('/en/checkout/place', $form)->assertRedirect();

        $order = Order::firstOrFail();
        expect($order->shipping_method_code)->toBe('express')->and($order->shipping_minor)->toBe(15000)->and($order->cod_fee_minor)->toBe(2000)->and($order->grand_total_minor)->toBe(65000 + 15000 + 2000);
    });
});

describe('placing twice, going back, and prices that move', function () {
    it('makes one order when the place button is pressed twice', function () {
        ($this->cart)($this->tee, 1);
        ($this->goToReview)();
        $form = ($this->placeForm)();

        $a = ($this->as)()->post('/en/checkout/place', $form)->assertRedirect()->headers->get('Location');
        $b = ($this->as)()->post('/en/checkout/place', $form)->assertRedirect()->headers->get('Location');

        expect(Order::count())->toBe(1)->and(parse_url($a, PHP_URL_PATH))->toBe(parse_url($b, PHP_URL_PATH))->and((int) DB::table('stock_levels')->where('variant_id', $this->tee)->value('reserved'))->toBe(1);
    });

    it('shows the new total instead of charging a changed one', function () {
        ($this->cart)($this->tee, 1);
        ($this->goToReview)();
        $form = ($this->placeForm)();
        app(SetRegularPrice::class)->handle(Actor::system('t'), $this->tee, Money::of(70000));   // the price moved while the shopper was reading

        ($this->as)()->post('/en/checkout/place', $form)->assertRedirect('/en/checkout/review')->assertSessionHasErrors(['checkout']);
        expect(Order::count())->toBe(0);

        // the redirect target shows why, and the new total
        $page = ($this->as)()->followingRedirects()->post('/en/checkout/place', $form)->assertOk();
        $page->assertSee('changed while you were checking out')->assertSee('760');   // ৳700 + ৳60
        expect(DB::table('stock_reservations')->count())->toBe(0);

        ($this->as)()->post('/en/checkout/place', ($this->placeForm)())->assertRedirect();
        expect(Order::firstOrFail()->grand_total_minor)->toBe(76000);
    });
});

describe('the confirmation page', function () {
    beforeEach(function () {
        ($this->cart)($this->tee, 1);
        ($this->goToReview)();
        ($this->as)()->post('/en/checkout/place', ($this->placeForm)());
        $this->order = Order::firstOrFail();
        $this->link = fn (?DateTimeInterface $expires = null, ?string $id = null) => URL::temporarySignedRoute('checkout.thanks', $expires ?? now()->addDay(), ['locale' => 'en', 'order' => $id ?? $this->order->public_id]);
    });

    it('needs the signed link: without it, with a changed signature, or after it expires, nothing is shown', function () {
        $this->get("/en/orders/{$this->order->public_id}/thanks")->assertForbidden();
        $this->get(($this->link)().'x')->assertForbidden();
        $this->get(str_replace($this->order->public_id, strtolower((string) Str::ulid()), ($this->link)()))->assertForbidden();   // another order's id with this order's signature
        $this->get(($this->link)(now()->subMinute()))->assertForbidden();
        $this->get(($this->link)())->assertOk()->assertSee($this->order->order_number);
    });

    it('is a 404 for an order that does not exist, even with a valid signature', function () {
        $this->get(($this->link)(null, strtolower((string) Str::ulid())))->assertNotFound();
    });

    it('is reachable again by the back button / a second press after success', function () {
        $again = ($this->as)()->post('/en/checkout/place', ['place_token' => str_repeat('x', 40), 'expected_total' => '1', 'payment' => 'cod'])->assertRedirect()->headers->get('Location');

        expect($again)->toContain($this->order->public_id)->and(Order::count())->toBe(1);
    });
});

describe('signed-in customers', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->mine = CartOwner::user($this->user->id);
        app(AddToCart::class)->handle($this->mine, $this->tee, 1);
        $this->input = fn (string $name = 'Karim') => new AddressInput($name, '01812345678', $this->division, $this->dhaka, $this->upazila, 'Flat 4B, Road 9', null, null, null, '1209', 'Home');
    });

    it('offers the saved addresses and orders to the one chosen, as that customer', function () {
        $saved = app(AddressBook::class)->save($this->user->id, null, ($this->input)());

        $this->actingAs($this->user, 'web')->get('/en/checkout')->assertOk()->assertSee('Karim')->assertSee('Flat 4B, Road 9')->assertSee('Use a different address');
        $this->actingAs($this->user, 'web')->post('/en/checkout', ['saved_address' => $saved->publicId, 'email' => 'k@example.com'])->assertRedirect('/en/checkout/review');
        $this->actingAs($this->user, 'web')->get('/en/checkout/review')->assertOk()->assertSee('Karim')->assertSee('Flat 4B, Road 9');
        $html = $this->actingAs($this->user, 'web')->get('/en/checkout/review')->getContent();
        preg_match('/name="place_token" value="([^"]+)"/', $html, $t);
        preg_match('/name="expected_total" value="(\d+)"/', $html, $e);
        $this->actingAs($this->user, 'web')->post('/en/checkout/place', ['place_token' => $t[1], 'expected_total' => $e[1], 'shipping' => 'standard', 'payment' => 'cod'])->assertRedirect();

        $order = Order::firstOrFail();
        expect($order->user_id)->toBe($this->user->id)->and($order->contact_name)->toBe('Karim')->and($order->contact_phone)->toBe('+8801812345678')->and($order->addresses[0]->address_line)->toBe('Flat 4B, Road 9');
    });

    it('cannot order to somebody else\'s saved address', function () {
        $other = User::factory()->create();
        $theirs = app(AddressBook::class)->save($other->id, null, ($this->input)('Not Mine'));

        $this->actingAs($this->user, 'web')->post('/en/checkout', ['saved_address' => $theirs->publicId])->assertSessionHasErrors('saved_address');
        $this->actingAs($this->user, 'web')->get('/en/checkout/review')->assertRedirect('/en/checkout');

        // a guest cannot use a saved address id at all
        ($this->as)();
        $this->post('/en/checkout', ['saved_address' => $theirs->publicId])->assertSessionHasErrors();
        expect(Order::count())->toBe(0);
    });

    it('can save a newly typed address to the address book while checking out', function () {
        $this->actingAs($this->user, 'web')->post('/en/checkout', ($this->form)(['recipient' => 'Karim', 'save_address' => '1']))->assertRedirect('/en/checkout/review');

        // customer_addresses points at the customer record, not at the user id
        expect(DB::table('customer_addresses')->join('customers', 'customers.id', '=', 'customer_addresses.customer_id')->where('customers.user_id', $this->user->id)->count())->toBe(1);
    });
});

describe('carts with only a download or a service', function () {
    it('asks for contact details instead of an address, and says online payment is not ready', function () {
        app(AddToCart::class)->handle($this->owner, $this->ebook, 1);

        ($this->as)()->get('/en/checkout')->assertOk()->assertSee('Your details')->assertDontSee('Division');
        ($this->as)()->post('/en/checkout', ['contact_name' => 'Rahim', 'contact_phone' => '01712345678'])->assertRedirect('/en/checkout/review');
        ($this->as)()->get('/en/checkout/review')->assertOk()->assertSee('Online payment is coming soon')->assertSee('disabled', false);
        ($this->as)()->post('/en/checkout', ['contact_name' => '', 'contact_phone' => '01712345678'])->assertSessionHasErrors('contact_name');
    });
});

describe('telling the customer', function () {
    beforeEach(function () {
        config(['services.sms.driver' => 'fake']);
        $this->sms = app(FakeSmsGateway::class);
        Mail::fake();
        $this->placeOne = function (string $locale = 'en', array $over = []) {
            ($this->cart)($this->tee, 1);
            ($this->goToReview)($locale, $over);
            ($this->as)()->post("/{$locale}/checkout/place", ($this->placeForm)($locale))->assertRedirect();

            return Order::latest('id')->firstOrFail();
        };
    });

    it('sends an SMS and an email through the outbox, carrying the order number, the total and a working link', function () {
        $order = ($this->placeOne)();

        expect($this->sms->sent())->toHaveCount(1)->and($this->sms->sent()[0]['to'])->toBe('+8801712345678')->and($this->sms->sent()[0]['message'])->toContain($order->order_number)->toContain('710.00')->toContain('Pay cash on delivery');
        Mail::assertSent(OrderPlacedMail::class, function (OrderPlacedMail $m) use ($order) {
            $html = $m->render();

            return $m->hasTo('rahim@example.com') && str_contains($html, $order->order_number) && str_contains($html, 'signature=') && str_contains($html, 'Cotton Tee');
        });
    });

    it('sends each message at most once even if the outbox delivers the event again', function () {
        $order = ($this->placeOne)();
        $outboxId = (int) DB::table('outbox_messages')->where('event_name', 'orders.placed')->value('id');
        $event = tap(new OrderPlaced($order->id, $order->public_id, $order->order_number, 'en'), fn ($e) => $e->outboxId = $outboxId);

        app(SendOrderConfirmation::class)->handle($event);
        app(SendOrderConfirmation::class)->handle($event);

        expect($this->sms->sent())->toHaveCount(1);
        Mail::assertSent(OrderPlacedMail::class, 1);
    });

    it('does not lose the order when the SMS provider is down, and retries without a second email', function () {
        $this->sms->fail = true;
        $order = ($this->placeOne)();   // the order is placed; only the message failed
        expect(Order::count())->toBe(1)->and($this->sms->sent())->toHaveCount(0);
        Mail::assertNothingSent();

        $this->sms->fail = false;
        $outboxId = (int) DB::table('outbox_messages')->where('event_name', 'orders.placed')->value('id');
        $event = tap(new OrderPlaced($order->id, $order->public_id, $order->order_number, 'en'), fn ($e) => $e->outboxId = $outboxId);
        app(SendOrderConfirmation::class)->handle($event);
        app(SendOrderConfirmation::class)->handle($event);

        expect($this->sms->sent())->toHaveCount(1);
        Mail::assertSent(OrderPlacedMail::class, 1);
    });

    it('writes the SMS in Bangla for a Bangla order, and sends no email without an address', function () {
        $order = ($this->placeOne)('bn', ['email' => '']);

        expect($order->locale)->toBe('bn')->and($this->sms->sent())->toHaveCount(1)->and($this->sms->sent()[0]['message'])->toContain('অর্ডার')->toContain($order->order_number)->toContain('পৌঁছালে নগদ দিন');
        Mail::assertNothingSent();
    });
});

it('has Bangla wording for everything checkout says', function () {
    $keys = function (array $a, string $prefix = '') use (&$keys): array {
        $out = [];
        foreach ($a as $k => $v) {
            is_array($v) ? array_push($out, ...$keys($v, $prefix.$k.'.')) : $out[] = $prefix.$k;
        }

        return $out;
    };

    foreach (['checkout', 'notifications', 'orders'] as $file) {
        $en = $keys(require app_path("Modules/Orders/resources/lang/en/{$file}.php"));
        $bn = $keys(require app_path("Modules/Orders/resources/lang/bn/{$file}.php"));
        expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
    }
});
