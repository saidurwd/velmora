<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
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
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\PlaceOrderCommand;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\CheckoutException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\CheckoutPricing;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, GeographySeeder::class, ShippingSeeder::class, PaymentMethodSeeder::class]);
    $actor = Actor::system('t');
    $category = app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', 'General')]);

    $this->make = function (string $sku, string $en, string $bn, int $taka, int $stock = 10, ProductType $type = ProductType::Physical, int $weight = 300) use ($actor, $category): array {
        $product = app(SaveProduct::class)->handle($actor, null, new ProductData($type, [new TranslationInput('en', $en), new TranslationInput('bn', $bn)], categoryIds: [$category->id],
            defaultVariant: new VariantData($sku, trackInventory: $type === ProductType::Physical, weightG: $type === ProductType::Physical ? $weight : null)));
        $variantId = (int) $product->variants()->value('id');
        app(SetRegularPrice::class)->handle($actor, $variantId, Money::of($taka * 100));
        if ($type === ProductType::Physical) {
            DB::table('stock_levels')->insert(['variant_id' => $variantId, 'location_id' => (int) DB::table('stock_locations')->value('id'), 'on_hand' => $stock, 'reserved' => 0, 'updated_at' => now()]);
        }
        app(TransitionProduct::class)->publish($actor, $product);

        return ['variantId' => $variantId];
    };
    $this->tee = ($this->make)('TEE-1', 'Cotton Tee', 'সুতির টি-শার্ট', 650, 5);
    $this->mug = ($this->make)('MUG-1', 'Mug', 'মগ', 350, 5);
    $this->ebook = ($this->make)('EBOOK-1', 'E-book', 'ই-বুক', 200, 0, ProductType::Digital);

    $this->guest = str_repeat('a', 40);
    $this->owner = CartOwner::guest($this->guest);
    $this->addToCart = fn (array $v, int $qty = 1, ?CartOwner $owner = null) => app(AddToCart::class)->handle($owner ?? $this->owner, $v['variantId'], $qty);

    $this->dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->division = (int) DB::table('districts')->where('id', $this->dhaka)->value('division_id');
    $this->upazila = (int) DB::table('upazilas')->where('district_id', $this->dhaka)->value('id');
    $this->address = fn (string $note = 'Call before delivery') => app(AddressChecker::class)->check(new AddressInput('Rahim Uddin', '017১২৩৪৫৬৭৮', $this->division, $this->dhaka, $this->upazila, 'House 5, Road 2', 'Dhanmondi', 'Near the lake', $note, '1205'));

    $this->total = fn (?int $district = null, string $method = 'standard', string $pay = 'cod') => app(CheckoutPricing::class)->quote(app(CartReader::class)->view($this->owner, 'en'), $district ?? $this->dhaka, $method, $pay, 'en')->breakdown->grandTotalMinor;
    $this->command = fn (array $over = []) => new PlaceOrderCommand(...array_replace([
        'actor' => Actor::guest('checkout'), 'owner' => $this->owner, 'userId' => null, 'contactName' => 'Rahim Uddin', 'contactPhone' => '01712345678', 'contactEmail' => ' Rahim@Example.com ',
        'address' => ($this->address)(), 'shippingMethodCode' => 'standard', 'paymentMethodCode' => 'cod', 'expectedTotalMinor' => ($this->total)(), 'placeToken' => 'token-'.Str::random(12), 'locale' => 'en',
    ], $over));
    $this->place = fn (array $over = []) => app(PlaceOrder::class)->handle(($this->command)($over));
    $this->counts = fn () => [
        'orders' => DB::table('orders')->count(), 'items' => DB::table('order_items')->count(), 'reserved' => (int) DB::table('stock_levels')->sum('reserved'),
        'reservations' => DB::table('stock_reservations')->count(), 'redemptions' => DB::table('coupon_redemptions')->count(), 'outbox' => DB::table('outbox_messages')->where('event_name', 'orders.placed')->count(),
        'cart_items' => DB::table('cart_items')->count(),
    ];
    $this->coupon = fn (string $code, array $over = []) => DB::table('coupons')->insertGetId($over + ['public_id' => strtolower((string) Str::ulid()), 'code' => $code, 'type' => 'percent', 'value' => 1000, 'scope' => 'all', 'is_active' => true, 'min_spend_minor' => 0, 'used_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $this->cartCode = fn (string $code) => DB::table('cart_coupons')->insert(['cart_id' => DB::table('carts')->value('id'), 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
    $this->expectFailure = function (string $reason, array $over = []) {
        $before = ($this->counts)();
        expect(fn () => ($this->place)($over))->toThrow(CheckoutException::class, $reason);
        expect(($this->counts)())->toBe($before);   // nothing written, nothing held, nothing used, cart untouched
    };
});

describe('a successful order', function () {
    it('turns the cart into an order, holds the stock, empties the cart and announces it', function () {
        ($this->addToCart)($this->tee, 2);
        ($this->addToCart)($this->mug, 1);
        ($this->addToCart)($this->ebook, 1);

        // the ebook makes this a mixed cart: ৳1,300 + ৳350 + ৳200 + ৳60 delivery
        $order = ($this->place)();

        expect($order->order_number)->toMatch('/^VM-\d{4}-\d{6}$/')->and($order->status)->toBe(OrderStatus::Pending)->and($order->grand_total_minor)->toBe(185000 + 6000)
            ->and($order->contact_phone)->toBe('+8801712345678')->and($order->contact_email)->toBe('rahim@example.com')->and($order->user_id)->toBeNull()
            ->and($order->payment_method_code)->toBe('cod')->and($order->shipping_method_code)->toBe('standard')->and($order->shipping_minor)->toBe(6000)
            ->and($order->items)->toHaveCount(3)->and($order->items[0]->name('bn'))->toBe('সুতির টি-শার্ট')->and($order->items[0]->name('en'))->toBe('Cotton Tee')
            ->and($order->addresses[0]->recipient_name)->toBe('Rahim Uddin')->and($order->addresses[0]->phone)->toBe('+8801712345678')->and($order->addresses[0]->delivery_note)->toBe('Call before delivery')
            ->and($order->addresses[0]->locationLine('bn'))->toContain('ঢাকা')->and($order->addresses[0]->postal_code)->toBe('1205');

        // 2 tees and 1 mug held for this order, with no expiry; the e-book is not stock-tracked
        $held = DB::table('stock_reservations')->where('owner_type', 'order')->where('owner_ref', $order->public_id)->get();
        expect($held)->toHaveCount(2)->and($held->every(fn ($r) => $r->expires_at === null && $r->status === 'active'))->toBeTrue()
            ->and((int) DB::table('stock_levels')->where('variant_id', $this->tee['variantId'])->value('reserved'))->toBe(2)
            ->and((int) DB::table('stock_levels')->where('variant_id', $this->mug['variantId'])->value('reserved'))->toBe(1);

        expect(DB::table('cart_items')->count())->toBe(0)->and(app(CartReader::class)->view($this->owner, 'en')->isEmpty())->toBeTrue()
            ->and(DB::table('outbox_messages')->where('event_name', 'orders.placed')->count())->toBe(1)
            ->and(json_decode((string) DB::table('outbox_messages')->where('event_name', 'orders.placed')->value('payload'), true)['order_number'])->toBe($order->order_number)
            ->and($order->history->map(fn ($h) => $h->axis.':'.$h->to_state)->all())->toBe(['status:pending', 'payment:unpaid', 'fulfillment:unfulfilled']);
    });

    it('uses the coupon, spreads the discount over the lines, and charges the discounted total', function () {
        ($this->addToCart)($this->tee, 2);   // ৳1,300
        $id = ($this->coupon)('SAVE10', ['usage_limit' => 5]);
        ($this->cartCode)('SAVE10');

        $order = ($this->place)();

        expect($order->discount_minor)->toBe(13000)->and($order->grand_total_minor)->toBe(130000 - 13000 + 6000)->and($order->items[0]->discount_minor)->toBe(13000)->and($order->items[0]->line_total_minor)->toBe(117000)
            ->and((int) DB::table('coupons')->where('id', $id)->value('used_count'))->toBe(1)
            ->and(DB::table('coupon_redemptions')->where('coupon_id', $id)->get(['order_id', 'amount_minor', 'redeemer'])->map(fn ($r) => [$r->order_id, $r->amount_minor, str_starts_with($r->redeemer, 'p:')])->all())->toBe([[$order->id, 13000, true]])
            ->and(DB::table('cart_coupons')->count())->toBe(0);
    });

    it('makes delivery free with a free-delivery code', function () {
        ($this->addToCart)($this->tee, 1);
        ($this->coupon)('SHIP', ['type' => 'free_shipping', 'value' => 0]);
        ($this->cartCode)('SHIP');

        $order = ($this->place)();

        expect($order->shipping_minor)->toBe(0)->and($order->grand_total_minor)->toBe(65000);
    });

    it('adds the cash-on-delivery fee the shop set for that delivery method', function () {
        ($this->addToCart)($this->tee, 1);
        DB::table('shipping_methods')->where('code', 'standard')->update(['cod_fee_minor' => 3000]);

        $order = ($this->place)();

        expect($order->cod_fee_minor)->toBe(3000)->and($order->grand_total_minor)->toBe(65000 + 6000 + 3000);
    });

    it('can confirm cash-on-delivery orders at once when the shop chooses so', function () {
        config(['orders.auto_confirm_cod' => true]);
        ($this->addToCart)($this->tee, 1);

        $order = ($this->place)();

        expect($order->status)->toBe(OrderStatus::Confirmed)->and($order->history->firstWhere('axis', 'status')->to_state)->toBe('confirmed');
    });

    it('charges the Bangladeshi customer record to the signed-in account when there is one', function () {
        $user = User::factory()->create();
        $mine = CartOwner::user($user->id);
        ($this->addToCart)($this->tee, 1, $mine);

        $order = app(PlaceOrder::class)->handle(new PlaceOrderCommand(Actor::guest('x'), $mine, $user->id, 'Rahim', '01712345678', null, ($this->address)(), 'standard', 'cod',
            app(CheckoutPricing::class)->quote(app(CartReader::class)->view($mine, 'en'), $this->dhaka, 'standard', 'cod', 'en')->breakdown->grandTotalMinor, 'tok-'.Str::random(10), 'en'));

        expect($order->user_id)->toBe($user->id)->and(DB::table('coupon_redemptions')->count())->toBe(0);
    });
});

describe('placing the same checkout twice', function () {
    it('gives back the same order, and takes the stock only once', function () {
        ($this->addToCart)($this->tee, 2);
        $cmd = ($this->command)();

        $first = app(PlaceOrder::class)->handle($cmd);
        $again = app(PlaceOrder::class)->handle($cmd);   // double-click, browser retry, back button

        expect($again->id)->toBe($first->id)->and(Order::count())->toBe(1)->and((int) DB::table('stock_levels')->where('variant_id', $this->tee['variantId'])->value('reserved'))->toBe(2)
            ->and(DB::table('outbox_messages')->where('event_name', 'orders.placed')->count())->toBe(1);
    });

    it('treats a different form (new token) on an empty cart as an empty cart, not a second order', function () {
        ($this->addToCart)($this->tee, 1);
        ($this->place)();

        expect(fn () => ($this->place)(['expectedTotalMinor' => 71000]))->toThrow(CheckoutException::class, 'cart_empty');
        expect(Order::count())->toBe(1);
    });

    it('keeps one shopper\'s token from replaying as another shopper\'s order', function () {
        ($this->addToCart)($this->tee, 1);
        $theirs = ($this->place)(['placeToken' => 'shared-token-0001']);

        $other = CartOwner::guest(str_repeat('b', 40));
        ($this->addToCart)($this->mug, 1, $other);
        $mine = app(PlaceOrder::class)->handle(($this->command)(['owner' => $other, 'placeToken' => 'shared-token-0001', 'expectedTotalMinor' => 35000 + 6000]));

        expect($mine->id)->not->toBe($theirs->id)->and(Order::count())->toBe(2);
    });
});

describe('refusing to place an order, and leaving nothing behind', function () {
    it('refuses when the total changed since the shopper saw it (a price moved)', function () {
        ($this->addToCart)($this->tee, 1);
        $shown = ($this->total)();
        app(SetRegularPrice::class)->handle(Actor::system('t'), $this->tee['variantId'], Money::of(70000));

        ($this->expectFailure)('totals_changed', ['expectedTotalMinor' => $shown]);

        // and the shopper can place it at the new price
        expect(($this->place)()->grand_total_minor)->toBe(70000 + 6000);
    });

    it('refuses when a coupon was removed or stopped working since the page was shown', function () {
        ($this->addToCart)($this->tee, 2);
        $id = ($this->coupon)('SAVE10');
        ($this->cartCode)('SAVE10');
        $shown = ($this->total)();
        DB::table('coupons')->where('id', $id)->update(['is_active' => false]);

        ($this->expectFailure)('totals_changed', ['expectedTotalMinor' => $shown]);
    });

    it('gives back the held stock and the coupon use when a later step fails', function () {
        ($this->addToCart)($this->tee, 2);
        $id = ($this->coupon)('ONCEEACH', ['per_customer_limit' => 1, 'usage_limit' => 9]);
        ($this->cartCode)('ONCEEACH');
        // this phone already used it: the cart could not know (guests are anonymous), the order moment does
        DB::table('coupon_redemptions')->insert(['coupon_id' => $id, 'order_id' => (function () {
            fakeOrders([991]);

            return 991;
        })(), 'redeemer' => 'p:'.hash('sha256', '+8801712345678'), 'amount_minor' => 1, 'redeemed_at' => now()]);
        DB::table('coupons')->where('id', $id)->update(['used_count' => 1]);

        ($this->expectFailure)('coupon_failed', ['expectedTotalMinor' => ($this->total)()]);

        expect((int) DB::table('coupons')->where('id', $id)->value('used_count'))->toBe(1)->and(DB::table('cart_coupons')->count())->toBe(1);
    });

    it('rolls the order back when the stock cannot be held', function () {
        ($this->addToCart)($this->tee, 1);
        $this->app->instance(StockReservations::class, new class implements StockReservations
        {
            public function holdForOrder(array $lines, string $orderRef): void
            {
                throw new DomainException('short');
            }

            public function releaseForOrder(string $orderRef): int
            {
                return 0;
            }
        });

        ($this->expectFailure)('insufficient_stock');
    });

    it('refuses what the shop cannot deliver or take payment for', function () {
        ($this->addToCart)($this->tee, 1);

        ($this->expectFailure)('shipping_method_invalid', ['shippingMethodCode' => 'express']);
        ($this->expectFailure)('payment_method_invalid', ['paymentMethodCode' => 'bkash']);
        ($this->expectFailure)('address_required', ['address' => null]);
        ($this->expectFailure)('phone_invalid', ['contactPhone' => '12345']);

        DB::table('shipping_zones')->update(['cod_available' => false]);
        ($this->expectFailure)('payment_method_invalid');   // no cash on delivery where the zone does not allow it
    });

    it('cannot check out a cart with only downloads or services yet (no online payment until Phase 6)', function () {
        ($this->addToCart)($this->ebook, 1);

        ($this->expectFailure)('payment_method_invalid', ['address' => null]);
    });

    it('refuses an empty cart and a cart with an item that can no longer be bought', function () {
        ($this->expectFailure)('cart_empty');

        ($this->addToCart)($this->tee, 1);
        DB::table('stock_levels')->where('variant_id', $this->tee['variantId'])->update(['on_hand' => 0]);
        ($this->expectFailure)('cart_problem');
    });
});

describe('the stock of the last unit', function () {
    it('goes to one of two shoppers, and the other is told why', function () {
        DB::table('stock_levels')->where('variant_id', $this->tee['variantId'])->update(['on_hand' => 1]);
        $a = CartOwner::guest(str_repeat('c', 40));
        $b = CartOwner::guest(str_repeat('d', 40));
        ($this->addToCart)($this->tee, 1, $a);
        ($this->addToCart)($this->tee, 1, $b);
        $total = 65000 + 6000;

        $first = app(PlaceOrder::class)->handle(($this->command)(['owner' => $a, 'expectedTotalMinor' => $total]));
        expect(fn () => app(PlaceOrder::class)->handle(($this->command)(['owner' => $b, 'expectedTotalMinor' => $total])))->toThrow(CheckoutException::class, 'cart_problem');

        expect($first->id)->toBeInt()->and(Order::count())->toBe(1)->and((int) DB::table('stock_levels')->where('variant_id', $this->tee['variantId'])->value('reserved'))->toBe(1);
    });
});

describe('cancelling releases the stock', function () {
    it('gives the held units back when the order is cancelled', function () {
        ($this->addToCart)($this->tee, 3);
        $order = ($this->place)();
        expect((int) DB::table('stock_levels')->where('variant_id', $this->tee['variantId'])->value('reserved'))->toBe(3);

        app(TransitionOrder::class)->status(Actor::system('staff'), $order->id, OrderStatus::Cancelled, 'Customer asked');

        expect((int) DB::table('stock_levels')->where('variant_id', $this->tee['variantId'])->value('reserved'))->toBe(0)
            ->and(DB::table('stock_reservations')->where('owner_ref', $order->public_id)->pluck('status')->unique()->all())->toBe(['released']);
    });
});
