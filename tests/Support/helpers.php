<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SaveServicePackage;
use App\Modules\Bookings\Actions\SaveServiceResource;
use App\Modules\Bookings\Database\Seeders\CancellationPolicySeeder;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Customers\Actions\SaveAddress;
use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\DigitalProducts\Actions\UploadDigitalAsset;
use App\Modules\DigitalProducts\Database\Seeders\RefundPolicySeeder;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Inventory\Contracts\StockInitialiser;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Notifications\Actions\SaveMessagePreferences;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderAddress;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\Enums\FulfillmentStatus;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Outbox\OutboxDispatcher;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Pricing\Services\BreakdownCalculator;
use App\Modules\Shipping\Actions\CreateShipment;
use App\Modules\Shipping\Actions\HandOverShipment;
use App\Modules\Shipping\Actions\RecordCourierEvent;
use App\Modules\Shipping\Database\Seeders\CourierSeeder;
use App\Modules\Shipping\DTOs\CourierEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

// Shared test helpers (loaded from tests/Pest.php).

const GOOD_PASSWORD = 'correct-horse-battery';

/** The test client does not keep cookies between requests; carry the session cookie like a browser would. */
function carrySession(object $test, TestResponse $response): void
{
    $cookies = [];
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }
    $test->withUnencryptedCookies($cookies);
}

/** @param list<string> $permissions */
function catalogStaff(array $permissions): User
{
    $staff = User::factory()->staff()->create(['email' => 'cat'.uniqid().'@example.com']);
    foreach ($permissions as $p) {
        $staff->givePermissionTo($p);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $staff;
}

function enBn(string $en, ?string $bn = null): array
{
    return ['en' => ['name' => $en], 'bn' => ['name' => $bn ?? '']];
}

/**
 * Minimal real order rows with the given ids, for tests of things that point at an order (coupon redemptions, payments, ...).
 * `$over` replaces any column (for example `['payment_method_code' => 'online', 'subtotal_minor' => 150000, 'grand_total_minor' => 150000]`).
 *
 * @param  list<int>  $ids
 * @param  array<string, mixed>  $over
 */
function fakeOrders(array $ids, array $over = []): void
{
    foreach ($ids as $id) {
        DB::table('orders')->insertOrIgnore(array_replace([
            'id' => $id, 'public_id' => strtolower((string) Str::ulid()), 'order_number' => sprintf('VM-TEST-%06d', $id),
            'contact_name' => 'Test', 'contact_phone' => '+8801712345678', 'locale' => 'en', 'subtotal_minor' => 0, 'discount_minor' => 0, 'shipping_minor' => 0,
            'cod_fee_minor' => 0, 'tax_minor' => 0, 'grand_total_minor' => 0, 'tax_inclusive' => false, 'payment_method_code' => 'cod',
            'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }
}

/**
 * A small shop for fulfilment tests: two tracked products with stock, a courier account for the in-memory fake courier and its status words,
 * and closures to place confirmed orders (stock held), make parcels and look at the books. Assigns everything onto the Pest test case.
 */
function shippingWorld(object $t): void
{
    $system = Actor::system('t');
    $t->seed([LanguageSeeder::class, TaxClassSeeder::class, CourierSeeder::class, StockLocationSeeder::class]);
    $t->staff = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Amina');
    $category = app(SaveCategory::class)->handle($system, null, null, [new TranslationInput('en', 'General')]);

    $variant = function (string $sku, string $name, int $stock) use ($system, $category): int {
        $p = app(SaveProduct::class)->handle($system, null, new ProductData(ProductType::Physical, [new TranslationInput('en', $name), new TranslationInput('bn', $name)], categoryIds: [$category->id], defaultVariant: new VariantData($sku, trackInventory: true)));
        $id = (int) $p->variants()->value('id');
        app(StockInitialiser::class)->openingStock($system, $id, $stock);

        return $id;
    };
    $t->tee = $variant('TEE-1', 'Cotton Tee', 50);
    $t->mug = $variant('MUG-1', 'Mug', 50);

    $t->account = strtolower((string) Str::ulid());
    DB::table('courier_accounts')->insert(['public_id' => $t->account, 'courier' => 'fake', 'label' => 'Fake', 'is_active' => 1, 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['PICKED' => 'handed_over', 'IN_TRANSIT' => 'in_transit', 'OUT_FOR_DELIVERY' => 'out_for_delivery', 'DELIVERED' => 'delivered', 'DELIVERY_FAILED' => 'failed', 'RETURNED' => 'returned'] as $word => $meaning) {
        DB::table('courier_status_map')->insert(['courier' => 'fake', 'provider_status' => $word, 'internal_status' => $meaning, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A confirmed order (tee ৳650.00, mug ৳350.00, delivery ৳60, cash fee ৳20 for cash orders) with its stock held. $lines: [variant id => quantity]. $opts: lineDiscount, taxBp, inclusive, orderDiscount. */
    $t->order = function (array $lines, string $method = 'cod', array $opts = []) use ($t, $system): object {
        $prices = [$t->tee => 65000, $t->mug => 35000];
        $calc = [];
        $newLines = [];
        foreach ($lines as $variantId => $qty) {
            $calc[] = ['variantId' => $variantId, 'quantity' => $qty, 'unitMinor' => $prices[$variantId], 'lineDiscountMinor' => $opts['lineDiscount'] ?? 0, 'taxRateBp' => $opts['taxBp'] ?? 0];
            $newLines[] = new NewOrderLine($variantId, 'physical', ['en' => $variantId === $t->tee ? 'Cotton Tee' : 'Mug', 'bn' => 'পণ্য'], $variantId === $t->tee ? 'TEE-1' : 'MUG-1');
        }
        $breakdown = (new BreakdownCalculator)->calculate($calc, $opts['inclusive'] ?? false, $opts['orderDiscount'] ?? 0, 6000, $method === 'cod' ? 2000 : 0);
        $address = new NewOrderAddress('shipping', 'Rahim Uddin', '+8801712345678', 3, 30, 300, ['division' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'district' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'upazila' => ['en' => 'Dhanmondi', 'bn' => 'ধানমন্ডি']], 'House 5, Road 2', '1205', 'Dhanmondi', null);
        $order = app(CreateOrder::class)->handle(new NewOrder(Actor::guest('checkout'), null, 'Rahim Uddin', '+8801712345678', null, OrderChannel::Web, 'en', $method, 'standard', $breakdown, $newLines, [$address]));
        app(StockReservations::class)->holdForOrder($lines, $order->public_id);
        if ($method !== 'cod') {
            app(TransitionOrder::class)->payment($system, $order->id, PaymentStatus::Paid, 'test');
        }
        app(TransitionOrder::class)->status($system, $order->id, OrderStatus::Confirmed, 'test');

        return DB::table('orders')->where('id', $order->id)->first();
    };
    $t->itemOf = fn (int $orderId, int $variantId) => (int) DB::table('order_items')->where('order_id', $orderId)->where('variant_id', $variantId)->value('id');
    /** A parcel with the fake courier (booked), handed over. $byVariant: [variant id => quantity]. */
    $t->parcel = function (object $order, array $byVariant, bool $handOver = true) use ($t) {
        $lines = collect($byVariant)->mapWithKeys(fn ($q, $v) => [($t->itemOf)($order->id, $v) => $q])->all();
        $shipment = app(CreateShipment::class)->handle($t->staff, $order->public_id, $lines, $t->account);

        return $handOver ? app(HandOverShipment::class)->handle($t->staff, $shipment->public_id) : $shipment;
    };
    $t->say = fn (object $shipment, string $word, string $at, ?string $note = null) => app(RecordCourierEvent::class)->handle('fake', new CourierEvent((string) $shipment->courier_consignment_id, $word, CarbonImmutable::parse($at, 'UTC'), $note), 'webhook');
    $t->state = fn (int $orderId) => DB::table('orders')->where('id', $orderId)->first(['status', 'fulfillment_status', 'payment_status', 'delivered_at']);
    $t->shipmentRow = fn (string $publicId) => DB::table('shipments')->where('public_id', $publicId)->first();
}

/**
 * Digital goods: three products (BOOK-1, BOOK-2 digital; TEE-1 physical), a fake private disk that can sign addresses, and helpers.
 *   $t->place([variant id => qty], ?int $userId, string $phone)  an online order (not paid)       $t->pay($orderId)   paid, and the rights granted
 *   $t->upload('BOOK-1', 'Cooking.pdf')                          puts a file in                     $t->rights()        every entitlement
 */
function digitalWorld(object $t): void
{
    $t->seed([LanguageSeeder::class, TaxClassSeeder::class, PaymentMethodSeeder::class, RefundPolicySeeder::class]);
    Storage::fake('private');
    config(['digital.disk' => 'private']);
    Storage::disk('private')->buildTemporaryUrlsUsing(fn (string $path, $expiration, array $options) => 'https://files.test/'.$path.'?exp='.$expiration->getTimestamp().'&disp='.rawurlencode((string) ($options['ResponseContentDisposition'] ?? '')));

    $system = Actor::system('t');
    $category = app(SaveCategory::class)->handle($system, null, null, [new TranslationInput('en', 'General')]);
    $make = fn (string $sku, string $en, string $bn, ProductType $type): int => (int) app(SaveProduct::class)->handle($system, null, new ProductData($type, [new TranslationInput('en', $en), new TranslationInput('bn', $bn)], categoryIds: [$category->id], defaultVariant: new VariantData($sku)))->variants()->value('id');
    $t->book = $make('BOOK-1', 'Cooking e-book', 'রান্নার বই', ProductType::Digital);
    $t->book2 = $make('BOOK-2', 'Garden e-book', 'বাগানের বই', ProductType::Digital);
    $t->tee = $make('TEE-1', 'Cotton Tee', 'টি-শার্ট', ProductType::Physical);
    $t->types = [$t->book => 'digital', $t->book2 => 'digital', $t->tee => 'physical'];

    $t->place = function (array $lines, ?int $userId = null, string $phone = '+8801712345678', ?string $email = null, string $locale = 'en') use ($t): object {
        $calc = [];
        $newLines = [];
        foreach ($lines as $variantId => $qty) {
            $calc[] = ['variantId' => $variantId, 'quantity' => $qty, 'unitMinor' => 35000, 'lineDiscountMinor' => 0, 'taxRateBp' => 0];
            $newLines[] = new NewOrderLine($variantId, $t->types[$variantId], ['en' => 'Item', 'bn' => 'পণ্য'], 'SKU-'.$variantId);
        }
        $physical = in_array('physical', array_map(fn ($v) => $t->types[$v], array_keys($lines)), true);
        $addresses = $physical ? [new NewOrderAddress('shipping', 'Rahim', $phone, 3, 30, 300, ['division' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'district' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'upazila' => ['en' => 'D', 'bn' => 'ড']], 'House 5', '1205', 'D', null)] : [];

        return app(CreateOrder::class)->handle(new NewOrder(Actor::guest('t'), $userId, 'Rahim', $phone, $email, OrderChannel::Web, $locale, 'online', $physical ? 'standard' : null, (new BreakdownCalculator)->calculate($calc, false, 0, 0, 0), $newLines, $addresses));
    };
    $t->pay = function (int $orderId): void {
        app(TransitionOrder::class)->status(Actor::system('t'), $orderId, OrderStatus::Confirmed, 'test');
        app(TransitionOrder::class)->payment(Actor::system('t'), $orderId, PaymentStatus::Paid, 'test');
        app(OutboxDispatcher::class)->dispatchDue();   // what the scheduler / after-commit job does: the rights are made
    };
    $t->upload = function (string $sku, string $name = 'Book.pdf', string $body = "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\n") {
        $path = tempnam(sys_get_temp_dir(), 'dg');
        file_put_contents($path, $body);
        $version = app(UploadDigitalAsset::class)->handle(new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Amina'), $sku, $path, $name);
        @unlink($path);

        return $version;
    };
    $t->rights = fn () => DB::table('entitlements')->orderBy('id')->get();
}

/**
 * Bookings: two bookable services (SVC-30: 30 minutes with 10 minutes kept free after; SVC-60: 60 minutes), a room and a person who serve them, and helpers.
 *   $t->book($resourceId, $packageId, 'confirmed', '2026-12-01 10:00', 60)   inserts a booking straight into the table (UTC), blocked time includes the buffers
 */
function bookingsWorld(object $t): void
{
    $t->seed([LanguageSeeder::class, TaxClassSeeder::class, CancellationPolicySeeder::class]);
    $system = Actor::system('t');
    $category = app(SaveCategory::class)->handle($system, null, null, [new TranslationInput('en', 'General')]);
    $make = fn (string $sku, ProductType $type): int => (int) app(SaveProduct::class)->handle($system, null, new ProductData($type, [new TranslationInput('en', $sku), new TranslationInput('bn', $sku)], categoryIds: [$category->id], defaultVariant: new VariantData($sku)))->variants()->value('id');
    $t->variant30 = $make('SVC-30', ProductType::Service);
    $t->variant60 = $make('SVC-60', ProductType::Service);
    $t->tee = $make('TEE-1', ProductType::Physical);
    $t->staff = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Amina');

    $t->service30 = app(SaveServiceDefinition::class)->handle($t->staff, 'SVC-30', 'at_provider', 60, 60, 15, true);
    $t->service60 = app(SaveServiceDefinition::class)->handle($t->staff, 'SVC-60', 'online', 120, 30, 20, true);
    $t->package30 = app(SaveServicePackage::class)->handle($t->staff, 'SVC-30', 30, 0, 10, true);
    $t->package60 = app(SaveServicePackage::class)->handle($t->staff, 'SVC-60', 60, 5, 5, true);
    $t->room = app(SaveServiceResource::class)->handle($t->staff, null, 'Fitting room', 'room', true, ['SVC-30', 'SVC-60']);
    $t->tailor = app(SaveServiceResource::class)->handle($t->staff, null, 'Rahim (tailor)', 'staff', true, ['SVC-30']);

    $t->book = function (int $resourceId, int $packageId, string $status, string $startUtc, int $minutes) {
        $package = DB::table('service_packages')->where('id', $packageId)->first();
        $start = CarbonImmutable::parse($startUtc, 'UTC');
        $end = $start->addMinutes($minutes);
        $row = [
            'public_id' => strtolower((string) Str::ulid()), 'package_id' => $packageId, 'resource_id' => $resourceId, 'status' => $status, 'starts_at' => $start, 'ends_at' => $end,
            'blocks_from' => $start->subMinutes((int) $package->buffer_before_min), 'blocks_until' => $end->addMinutes((int) $package->buffer_after_min),
            'hold_expires_at' => $status === 'held' ? now()->addMinutes(15) : null, 'created_at' => now(), 'updated_at' => now(),
        ];

        return DB::table('service_bookings')->insertGetId($row);
    };
}

/**
 * A shop with digital goods and a physical product (digitalWorld), the staff roles, the geography, fake mail and SMS, and the helpers to make customers who have
 * everything a data export or an erasure must cover: a profile, an address, a paid digital order with its download right, a message choice.
 */
function privacyWorld(object $t): void
{
    digitalWorld($t);
    $t->seed([RolesAndPermissionsSeeder::class, GeographySeeder::class]);
    $t->withoutMiddleware(EnforceSessionPolicy::class);
    Mail::fake();
    config(['services.sms.driver' => 'fake']);
    $t->sms = app(FakeSmsGateway::class);
    $t->sms->fail = false;
    $t->manager = catalogStaff(['privacy.view', 'privacy.manage']);
    $t->viewer = catalogStaff(['privacy.view']);

    /** @return array{user: User, customerId: int, order: object, right: object} */
    $t->makeCustomer = function (string $name, string $email, string $phone) use ($t): array {
        $user = User::factory()->create(['name' => $name, 'email' => $email, 'phone' => $phone, 'locale' => 'en']);
        app(CustomerProfiles::class)->ensureForUser($user->id, $name, $email, $phone);
        $customerId = (int) DB::table('customers')->where('user_id', $user->id)->value('id');
        $dhaka = (int) DB::table('divisions')->where('name_en', 'Dhaka')->value('id');
        $district = (int) DB::table('districts')->where('division_id', $dhaka)->where('name_en', 'Dhaka')->value('id');
        $upazila = (int) DB::table('upazilas')->where('district_id', $district)->orderBy('id')->value('id');
        app(SaveAddress::class)->handle($customerId, null, new AddressInput($name, '01712345678', $dhaka, $district, $upazila, 'House 5, Road 3 for '.$name, 'Mirpur 10'));
        $order = ($t->place)([$t->book => 1], $user->id, $phone, $email);
        ($t->pay)($order->id);
        app(SaveMessagePreferences::class)->handle($user->id, ['email' => true, 'sms' => true], '22:00', '07:00');

        return ['user' => $user, 'customerId' => $customerId, 'order' => $order, 'right' => DB::table('entitlements')->where('order_id', $order->id)->first()];
    };
    /** Signed in as the customer, with a sign-in confirmation from just now (or without). */
    $t->as = fn (User $user, bool $confirmed = true) => $t->flushSession()->actingAs($user, 'web')->withSession($confirmed ? ['auth.recently_confirmed_at' => now()->getTimestamp()] : []);
    /** Finishes an order the way staff would, so it no longer blocks an erasure. */
    $t->finish = function (int $orderId): void {
        $move = app(TransitionOrder::class);
        $move->status(Actor::system('t'), $orderId, OrderStatus::Processing, 'test');
        $move->fulfillment(Actor::system('t'), $orderId, FulfillmentStatus::Fulfilled, 'test');
        $move->status(Actor::system('t'), $orderId, OrderStatus::Completed, 'test');
    };
}

/**
 * A small shop that can really sell: published products with a price and stock (a tee, a lamp sold in twos, an e-book, a service), and a way to write an order for a
 * customer that contains them (at whatever price the order was placed at, which need not be today's).
 */
function shopWorld(object $t): void
{
    $t->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, PaymentMethodSeeder::class]);
    $t->actor = Actor::system('t');
    $t->category = app(SaveCategory::class)->handle($t->actor, null, null, [new TranslationInput('en', 'General', 'general'), new TranslationInput('bn', 'সাধারণ')]);

    $t->make = function (string $en, string $bn, string $sku, int $taka, int $stock = 10, ProductType $type = ProductType::Physical, int $min = 1, ?int $max = null, int $step = 1, bool $publish = true) use ($t): array {
        $product = app(SaveProduct::class)->handle($t->actor, null, new ProductData($type, [new TranslationInput('en', $en), new TranslationInput('bn', $bn)], categoryIds: [$t->category->id],
            defaultVariant: new VariantData($sku, trackInventory: $type === ProductType::Physical, minQty: $min, maxQty: $max, qtyStep: $step)));
        $variantId = (int) $product->variants()->value('id');
        app(SetRegularPrice::class)->handle($t->actor, $variantId, Money::of($taka * 100));
        if ($type === ProductType::Physical) {
            DB::table('stock_levels')->insert(['variant_id' => $variantId, 'location_id' => (int) DB::table('stock_locations')->value('id'), 'on_hand' => $stock, 'reserved' => 0, 'updated_at' => now()]);
        }
        if ($publish) {
            app(TransitionProduct::class)->publish($t->actor, $product);
        }

        return ['product' => $product, 'variantId' => $variantId, 'publicId' => (string) DB::table('product_variants')->where('id', $variantId)->value('public_id'), 'slug' => (string) DB::table('product_translations')->where('product_id', $product->id)->where('locale', 'en')->value('slug')];
    };
    $t->setStock = fn (array $v, int $onHand) => DB::table('stock_levels')->where('variant_id', $v['variantId'])->update(['on_hand' => $onHand, 'reserved' => 0, 'updated_at' => now()]);
    $t->setPrice = fn (array $v, int $taka) => app(SetRegularPrice::class)->handle($t->actor, $v['variantId'], Money::of($taka * 100));
    $t->tee = ($t->make)('Cotton Tee', 'সুতির টি-শার্ট', 'TEE-1', 650, 10);
    $t->lamp = ($t->make)('Brass Lamp', 'পিতলের ল্যাম্প', 'LAMP-1', 2300, 20, min: 2, max: 6, step: 2);
    $t->ebook = ($t->make)('Cooking e-book', 'রান্নার ই-বুক', 'EBOOK-1', 350, 0, ProductType::Digital);

    /** An order of the given variants (variant id => quantity) at the unit price stated (default 100 taka, deliberately not today's price). */
    $t->orderFor = function (int $userId, array $lines, int $unitMinor = 10000, string $locale = 'en'): object {
        $calc = [];
        $newLines = [];
        $physical = false;
        foreach ($lines as $variantId => $qty) {
            $type = (string) DB::table('products')->where('id', DB::table('product_variants')->where('id', $variantId)->value('product_id'))->value('product_type');
            $physical = $physical || $type === 'physical';
            $calc[] = ['variantId' => $variantId, 'quantity' => $qty, 'unitMinor' => $unitMinor, 'lineDiscountMinor' => 0, 'taxRateBp' => 0];
            $newLines[] = new NewOrderLine($variantId, $type, ['en' => 'Item '.$variantId, 'bn' => 'পণ্য '.$variantId], 'SKU-'.$variantId);
        }
        $addresses = $physical ? [new NewOrderAddress('shipping', 'Rahim', '+8801712345678', 3, 30, 300, ['division' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'district' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'upazila' => ['en' => 'D', 'bn' => 'ড']], 'House 5', '1205', 'D', null)] : [];

        return app(CreateOrder::class)->handle(new NewOrder(Actor::guest('t'), $userId, 'Rahim', '+8801712345678', 'r@example.com', OrderChannel::Web, $locale, 'online', $physical ? 'standard' : null, (new BreakdownCalculator)->calculate($calc, false, 0, 0, 0), $newLines, $addresses));
    };
}
