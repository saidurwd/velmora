<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Services\GuestCartToken;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Customers\Contracts\AddressChecker;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Orders\Actions\BlockContact;
use App\Modules\Orders\Actions\ClearOrderReview;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\PlaceOrderCommand;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\CheckoutException;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\CheckoutPricing;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, GeographySeeder::class, ShippingSeeder::class, PaymentMethodSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $actor = Actor::system('t');
    $category = app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', 'General')]);

    $this->make = function (string $sku, string $en, int $taka, int $stock = 500) use ($actor, $category): int {
        $product = app(SaveProduct::class)->handle($actor, null, new ProductData(ProductType::Physical, [new TranslationInput('en', $en)], categoryIds: [$category->id], defaultVariant: new VariantData($sku, trackInventory: true, weightG: 300)));
        $variantId = (int) $product->variants()->value('id');
        app(SetRegularPrice::class)->handle($actor, $variantId, Money::of($taka * 100));
        DB::table('stock_levels')->insert(['variant_id' => $variantId, 'location_id' => (int) DB::table('stock_locations')->value('id'), 'on_hand' => $stock, 'reserved' => 0, 'updated_at' => now()]);
        app(TransitionProduct::class)->publish($actor, $product);

        return $variantId;
    };
    $this->tee = ($this->make)('TEE-1', 'Cotton Tee', 650);
    $this->big = ($this->make)('BIG-1', 'Big item', 10000);   // delivery is free over ৳3,000, so one is exactly ৳10,000

    $this->dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->division = (int) DB::table('districts')->where('id', $this->dhaka)->value('division_id');
    $this->upazila = (int) DB::table('upazilas')->where('district_id', $this->dhaka)->value('id');

    /** One shopper (one cart, one phone, one address) places one order. $over replaces command fields. */
    $this->orderFor = function (string $phone, array $over = [], ?int $variant = null, int $qty = 1, string $line = 'House 5, Road 2') {
        $owner = CartOwner::guest(md5($phone.Str::random(6)));
        app(AddToCart::class)->handle($owner, $variant ?? $this->tee, $qty);
        $address = app(AddressChecker::class)->check(new AddressInput('Rahim Uddin', $phone, $this->division, $this->dhaka, $this->upazila, $line, 'Dhanmondi', null, null, '1205'));
        $total = app(CheckoutPricing::class)->quote(app(CartReader::class)->view($owner, 'en'), $this->dhaka, 'standard', 'cod', 'en')->breakdown->grandTotalMinor;

        return app(PlaceOrder::class)->handle(new PlaceOrderCommand(...array_replace([
            'actor' => Actor::guest('t'), 'owner' => $owner, 'userId' => null, 'contactName' => 'Rahim Uddin', 'contactPhone' => $phone, 'contactEmail' => null, 'address' => $address,
            'shippingMethodCode' => 'standard', 'paymentMethodCode' => 'cod', 'expectedTotalMinor' => $total, 'placeToken' => 'tok-'.Str::random(14), 'locale' => 'en',
        ], $over)));
    };
    $this->refused = function (string $reason, callable $attempt) {
        $before = [Order::count(), DB::table('stock_levels')->sum('reserved')];
        expect($attempt)->toThrow(CheckoutException::class, $reason);
        expect([Order::count(), DB::table('stock_levels')->sum('reserved')])->toBe($before);
    };
    $this->setting = fn (string $key, mixed $value) => app(Settings::class)->set($key, $value);
    $this->staff = Actor::system('staff');
});

describe('the block list', function () {
    it('refuses a blocked phone, in any way it is typed, and says nothing about why', function () {
        $first = ($this->orderFor)('01712345678');
        app(BlockContact::class)->handle($this->staff, $first, true, false, 'Refused two parcels');

        ($this->refused)('not_accepted', fn () => ($this->orderFor)('+88 017 1234 5678'));
        ($this->refused)('not_accepted', fn () => ($this->orderFor)('০১৭১২৩৪৫৬৭৮'));
        expect(($this->orderFor)('01812345678')->id)->toBeInt();   // another phone is fine
    });

    it('refuses a blocked address even if it is typed differently or with another phone', function () {
        $first = ($this->orderFor)('01712345678', [], null, 1, 'House 5, Road 2');
        app(BlockContact::class)->handle($this->staff, $first, false, true, null);

        ($this->refused)('not_accepted', fn () => ($this->orderFor)('01912345678', [], null, 1, 'house  5 ROAD-2'));
        expect(($this->orderFor)('01912345678', [], null, 1, 'House 9, Road 4')->id)->toBeInt();   // a different address is fine
    });

    it('keeps only hashes, can be switched off, and ignores an expired entry', function () {
        $first = ($this->orderFor)('01712345678');
        app(BlockContact::class)->handle($this->staff, $first, true, true, 'x');
        app(BlockContact::class)->handle($this->staff, $first, true, true, 'x');   // blocking twice is one entry

        expect(DB::table('order_blocklist')->count())->toBe(2)->and(json_encode(DB::table('order_blocklist')->get()))->not->toContain('1712345678')->not->toContain('House 5');
        DB::table('order_blocklist')->update(['is_active' => false]);
        expect(($this->orderFor)('01712345678')->id)->toBeInt();

        DB::table('order_blocklist')->update(['is_active' => true, 'expires_at' => now()->subMinute()]);
        expect(($this->orderFor)('01712345678')->id)->toBeInt();
    });

    it('is enforced even when the other rules are switched off', function () {
        ($this->setting)('fraud.enabled', false);
        $first = ($this->orderFor)('01712345678');
        app(BlockContact::class)->handle($this->staff, $first, true, false, null);

        ($this->refused)('not_accepted', fn () => ($this->orderFor)('01712345678'));
    });
});

describe('open cash-on-delivery orders per phone', function () {
    it('allows three and refuses the fourth until one is finished', function () {
        $orders = [($this->orderFor)('01712345678'), ($this->orderFor)('01712345678'), ($this->orderFor)('01712345678')];

        ($this->refused)('too_many_open_cod', fn () => ($this->orderFor)('01712345678'));
        expect(($this->orderFor)('01812345678')->id)->toBeInt();   // another phone is not affected

        app(TransitionOrder::class)->status($this->staff, $orders[0]->id, OrderStatus::Cancelled, 'Customer cancelled');
        expect(($this->orderFor)('01712345678')->id)->toBeInt();   // a cancelled order frees a place

        ($this->refused)('too_many_open_cod', fn () => ($this->orderFor)('01712345678'));
    });

    it('does not count finished orders, and counts the number the owner sets', function () {
        $a = ($this->orderFor)('01712345678');
        DB::table('orders')->where('id', $a->id)->update(['status' => 'completed']);
        ($this->setting)('fraud.max_open_cod_per_phone', 1);

        $b = ($this->orderFor)('01712345678');   // the completed one does not count
        ($this->refused)('too_many_open_cod', fn () => ($this->orderFor)('01712345678'));
        expect($b->id)->toBeInt();

        ($this->setting)('fraud.max_open_cod_per_phone', 0);   // 0 = no limit
        expect(($this->orderFor)('01712345678')->id)->toBeInt();
    });

    it('is off when the rules are switched off', function () {
        ($this->setting)('fraud.enabled', false);
        foreach (range(1, 5) as $i) {
            ($this->orderFor)('01712345678');
        }

        expect(Order::count())->toBe(5);
    });

    it('replays the same submission instead of refusing it as one order too many', function () {
        ($this->setting)('fraud.max_open_cod_per_phone', 1);
        $owner = CartOwner::guest(str_repeat('a', 40));
        app(AddToCart::class)->handle($owner, $this->tee, 1);
        $address = app(AddressChecker::class)->check(new AddressInput('Rahim', '01712345678', $this->division, $this->dhaka, $this->upazila, 'House 5'));
        $cmd = new PlaceOrderCommand(Actor::guest('t'), $owner, null, 'Rahim', '01712345678', null, $address, 'standard', 'cod', 71000, 'same-token-0001', 'en');

        $a = app(PlaceOrder::class)->handle($cmd);
        $b = app(PlaceOrder::class)->handle($cmd);

        expect($b->id)->toBe($a->id)->and(Order::count())->toBe(1);
    });
});

describe('large cash orders need a code', function () {
    it('refuses a cash order above the limit until the phone is confirmed, and takes it once it is', function () {
        ($this->refused)('phone_confirmation_required', fn () => ($this->orderFor)('01712345678', [], $this->big, 2));   // ৳20,000

        $order = ($this->orderFor)('01712345678', ['phoneConfirmed' => true], $this->big, 2);
        expect($order->grand_total_minor)->toBe(2_000_000)->and($order->needs_review)->toBeFalse();
    });

    it('does not ask for a code at or below the limit', function () {
        expect(($this->orderFor)('01712345678', [], $this->big, 1)->grand_total_minor)->toBe(1_000_000);
    });

    it('asks for a code one paisa-step above the limit', function () {
        ($this->setting)('fraud.high_value_cod_minor', 71_000);
        expect(($this->orderFor)('01712345678', [], $this->tee, 1)->grand_total_minor)->toBe(71_000);   // equal: no code
        ($this->refused)('phone_confirmation_required', fn () => ($this->orderFor)('01812345678', [], $this->tee, 2));   // above: code
    });

    it('can be switched off by setting the limit to zero', function () {
        ($this->setting)('fraud.high_value_cod_minor', 0);

        expect(($this->orderFor)('01712345678', [], $this->big, 3)->grand_total_minor)->toBe(3_000_000);
    });
});

describe('orders held for review', function () {
    it('flags many orders from one phone in a day, places the order, and will not confirm it until a person looks', function () {
        ($this->setting)('fraud.max_open_cod_per_phone', 0);
        ($this->setting)('fraud.velocity_orders_per_day', 2);
        config(['orders.auto_confirm_cod' => true]);

        $a = ($this->orderFor)('01712345678');
        $b = ($this->orderFor)('01712345678');
        $c = ($this->orderFor)('01712345678');

        expect($a->needs_review)->toBeFalse()->and($a->status)->toBe(OrderStatus::Confirmed)->and($b->needs_review)->toBeFalse()
            ->and($c->needs_review)->toBeTrue()->and($c->status)->toBe(OrderStatus::Pending)   // auto-confirm does not apply to a flagged order
            ->and($c->flags->map(fn ($f) => [$f->rule_code, $f->score, $f->details])->all())->toBe([['velocity_phone', 40, ['orders_24h' => 2, 'limit' => 2]]]);

        expect(fn () => app(TransitionOrder::class)->status($this->staff, $c->id, OrderStatus::Confirmed))->toThrow(OrderException::class, 'review_pending');
    });

    it('does not count orders older than a day', function () {
        ($this->setting)('fraud.max_open_cod_per_phone', 0);
        ($this->setting)('fraud.velocity_orders_per_day', 2);
        // two orders from this phone, placed 25 hours ago (written directly: a real order's time cannot be changed afterwards)
        foreach ([8001, 8002] as $id) {
            DB::table('orders')->insert([
                'id' => $id, 'public_id' => strtolower((string) Str::ulid()), 'order_number' => "VM-OLD-{$id}", 'contact_name' => 'Old', 'contact_phone' => '+8801712345678', 'locale' => 'en',
                'subtotal_minor' => 0, 'discount_minor' => 0, 'shipping_minor' => 0, 'cod_fee_minor' => 0, 'tax_minor' => 0, 'grand_total_minor' => 0, 'tax_inclusive' => false,
                'payment_method_code' => 'cod', 'status' => 'completed', 'placed_at' => now()->subHours(25), 'created_at' => now()->subHours(25), 'updated_at' => now(),
            ]);
        }

        expect(($this->orderFor)('01712345678')->needs_review)->toBeFalse();
    });

    it('flags one address used by several different phones, however it is typed', function () {
        $first = ($this->orderFor)('01712345678', [], null, 1, 'House 5, Road 2');
        $second = ($this->orderFor)('01812345678', [], null, 1, 'house 5 road 2');
        $third = ($this->orderFor)('01912345678', [], null, 1, 'HOUSE 5, ROAD-2');

        expect($first->needs_review)->toBeFalse()->and($second->needs_review)->toBeFalse()->and($third->needs_review)->toBeTrue()
            ->and($third->flags[0]->rule_code)->toBe('address_many_phones')->and($third->flags[0]->details)->toBe(['phones_30d' => 3, 'limit' => 3]);
    });

    it('does not flag the same phone using its own address again, or a different address', function () {
        ($this->setting)('fraud.max_open_cod_per_phone', 0);
        foreach (range(1, 4) as $i) {
            expect(($this->orderFor)('01712345678', [], null, 1, 'House 5, Road 2')->needs_review)->toBeFalse();
        }
        expect(($this->orderFor)('01812345678', [], null, 1, 'House 77, Lane 3')->needs_review)->toBeFalse();
    });

    it('lets a person clear the review with a reason, keeps the flags, and then the order can be confirmed', function () {
        ($this->setting)('fraud.max_open_cod_per_phone', 0);
        ($this->setting)('fraud.velocity_orders_per_day', 1);
        ($this->orderFor)('01712345678');
        $flagged = ($this->orderFor)('01712345678');
        $clear = app(ClearOrderReview::class);

        expect(fn () => $clear->handle($this->staff, $flagged->id, '  '))->toThrow(OrderException::class, 'reason_required');
        $cleared = $clear->handle(new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Sara'), $flagged->id, 'Called the customer, it is a shop restocking');

        expect($cleared->needs_review)->toBeFalse()->and($cleared->flags)->toHaveCount(1)
            ->and(DB::table('order_notes')->where('order_id', $flagged->id)->value('body'))->toContain('Called the customer')
            ->and(fn () => $clear->handle($this->staff, $flagged->id, 'again'))->toThrow(OrderException::class, 'not_under_review');
        expect(app(TransitionOrder::class)->status($this->staff, $flagged->id, OrderStatus::Confirmed)->status)->toBe(OrderStatus::Confirmed);
    });

    it('can still cancel a flagged order', function () {
        ($this->setting)('fraud.max_open_cod_per_phone', 0);
        ($this->setting)('fraud.velocity_orders_per_day', 1);
        ($this->orderFor)('01712345678');
        $flagged = ($this->orderFor)('01712345678');

        expect(app(TransitionOrder::class)->status($this->staff, $flagged->id, OrderStatus::Cancelled, 'Fake order')->status)->toBe(OrderStatus::Cancelled);
    });

    it('keeps the flags on record: they cannot be changed or removed', function () {
        ($this->setting)('fraud.max_open_cod_per_phone', 0);
        ($this->setting)('fraud.velocity_orders_per_day', 1);
        ($this->orderFor)('01712345678');
        $flagged = ($this->orderFor)('01712345678');

        expect(fn () => DB::table('order_fraud_flags')->where('order_id', $flagged->id)->update(['score' => 0]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_fraud_flags')->where('order_id', $flagged->id)->delete())->toThrow(QueryException::class);
    });
});

describe('the checkout page and the code', function () {
    beforeEach(function () {
        config(['services.sms.driver' => 'fake']);
        $this->sms = app(FakeSmsGateway::class);
        $this->token = str_repeat('c', 40);
        $this->as = fn () => $this->withCookie(GuestCartToken::COOKIE, $this->token);
        app(AddToCart::class)->handle(CartOwner::guest($this->token), $this->big, 2);
        ($this->as)()->post('/en/checkout', [
            'recipient' => 'Rahim Uddin', 'phone' => '01712345678', 'division_id' => $this->division, 'district_id' => $this->dhaka, 'upazila_id' => $this->upazila, 'address_line' => 'House 5, Road 2',
        ])->assertRedirect('/en/checkout/review');
        $this->placeForm = function () {
            $html = ($this->as)()->get('/en/checkout/review')->getContent();
            preg_match('/name="place_token" value="([^"]+)"/', $html, $t);
            preg_match('/name="expected_total" value="(\d+)"/', $html, $e);

            return ['place_token' => $t[1], 'expected_total' => $e[1], 'shipping' => 'standard', 'payment' => 'cod'];
        };
    });

    it('asks for the code on the review page and keeps the order button off until it is entered', function () {
        ($this->as)()->get('/en/checkout/review')->assertOk()->assertSee('Confirm your phone number')->assertSee('+88017****5678')->assertSee('Send me the code')
            ->assertSee('disabled', false);
    });

    it('sends the code to the phone, accepts the right one, and then places the order', function () {
        ($this->as)()->post('/en/checkout/phone-code')->assertRedirect('/en/checkout/review')->assertSessionHas('code_status');
        expect($this->sms->sent())->toHaveCount(1)->and($this->sms->sent()[0]['to'])->toBe('+8801712345678');
        $code = $this->sms->lastCode();

        ($this->as)()->post('/en/checkout/phone-confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        expect(Order::count())->toBe(0);
        ($this->as)()->post('/en/checkout/phone-confirm', ['code' => $code])->assertRedirect('/en/checkout/review')->assertSessionHas('code_status');

        ($this->as)()->get('/en/checkout/review')->assertSee('is confirmed');
        ($this->as)()->post('/en/checkout/place', ($this->placeForm)())->assertRedirect();
        expect(Order::count())->toBe(1)->and(Order::firstOrFail()->grand_total_minor)->toBe(2_000_000);
    });

    it('does not place the order from a hand-made request that skips the code', function () {
        $form = ($this->placeForm)();

        ($this->as)()->post('/en/checkout/place', $form)->assertRedirect('/en/checkout/review')->assertSessionHasErrors('checkout');
        expect(Order::count())->toBe(0);
    });

    it('does not carry the confirmation over to a different phone number', function () {
        ($this->as)()->post('/en/checkout/phone-code');
        ($this->as)()->post('/en/checkout/phone-confirm', ['code' => $this->sms->lastCode()]);
        // the shopper goes back and changes the number
        ($this->as)()->post('/en/checkout', [
            'recipient' => 'Rahim Uddin', 'phone' => '01812345678', 'division_id' => $this->division, 'district_id' => $this->dhaka, 'upazila_id' => $this->upazila, 'address_line' => 'House 5, Road 2',
        ])->assertRedirect('/en/checkout/review');

        ($this->as)()->post('/en/checkout/place', ($this->placeForm)())->assertSessionHasErrors('checkout');
        expect(Order::count())->toBe(0);
    });

    it('says the same thing for every wrong code, and gives up after a few tries', function () {
        ($this->as)()->post('/en/checkout/phone-code');
        $real = $this->sms->lastCode();

        foreach (range(1, 5) as $i) {
            ($this->as)()->post('/en/checkout/phone-confirm', ['code' => '11111'.$i])->assertSessionHasErrors(['code' => 'That code did not work. Check it, or ask for a new one.']);
        }
        ($this->as)()->post('/en/checkout/phone-confirm', ['code' => $real])->assertSessionHasErrors('code');   // locked after too many tries
        expect(Order::count())->toBe(0);
    });

    it('works in Bangla too', function () {
        ($this->as)()->get('/bn/checkout/review')->assertOk()->assertSee('আপনার ফোন নম্বর নিশ্চিত করুন')->assertSee('কোড পাঠান');
    });
});

describe('what staff see and may do', function () {
    beforeEach(function () {
        ($this->setting)('fraud.max_open_cod_per_phone', 0);
        ($this->setting)('fraud.velocity_orders_per_day', 1);
        ($this->orderFor)('01712345678');
        $this->flagged = ($this->orderFor)('01712345678');
        $this->who = fn (array $p) => catalogStaff($p);
    });

    it('shows why an order is held, and the flagged orders are in the review queue', function () {
        $viewer = ($this->who)(['orders.view']);

        $this->actingAs($viewer, 'admin')->get('/admin/orders/'.$this->flagged->public_id)->assertOk()->assertSee('Needs review')->assertSee('Many orders from one phone in a day')->assertDontSee('Mark as reviewed');
        $this->actingAs($viewer, 'admin')->get('/admin/orders?review=1')->assertOk()->assertSee($this->flagged->order_number);
    });

    it('lets staff with orders.update clear the review, but only with a reason', function () {
        $updater = ($this->who)(['orders.view', 'orders.update']);

        $this->actingAs($updater, 'admin')->get('/admin/orders/'.$this->flagged->public_id)->assertSee('Mark as reviewed');
        $this->actingAs($updater, 'admin')->post("/admin/orders/{$this->flagged->public_id}/review/clear", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($updater, 'admin')->post("/admin/orders/{$this->flagged->public_id}/review/clear", ['reason' => 'Spoke to the customer'])->assertSessionHas('status');

        expect($this->flagged->fresh()->needs_review)->toBeFalse();
        $this->actingAs($updater, 'admin')->post("/admin/orders/{$this->flagged->public_id}/review/clear", ['reason' => 'again'])->assertSessionHasErrors('review');
    });

    it('needs the cancel permission to block a customer, and the block then applies at checkout', function () {
        $viewer = ($this->who)(['orders.view']);
        $updater = ($this->who)(['orders.view', 'orders.update']);
        $canceller = ($this->who)(['orders.view', 'orders.cancel']);

        $this->actingAs($viewer, 'admin')->post("/admin/orders/{$this->flagged->public_id}/block", ['phone' => '1'])->assertForbidden();
        $this->actingAs($updater, 'admin')->post("/admin/orders/{$this->flagged->public_id}/block", ['phone' => '1'])->assertForbidden();
        $this->actingAs($updater, 'admin')->post("/admin/orders/{$this->flagged->public_id}/review/clear", [])->assertSessionHasErrors('reason');
        $this->actingAs($viewer, 'admin')->post("/admin/orders/{$this->flagged->public_id}/review/clear", ['reason' => 'x'])->assertForbidden();

        $this->actingAs($canceller, 'admin')->post("/admin/orders/{$this->flagged->public_id}/block", [])->assertSessionHasErrors('review');
        $this->actingAs($canceller, 'admin')->post("/admin/orders/{$this->flagged->public_id}/block", ['phone' => '1', 'reason' => 'Fake orders'])->assertSessionHas('status');

        ($this->refused)('not_accepted', fn () => ($this->orderFor)('01712345678'));
    });
});
