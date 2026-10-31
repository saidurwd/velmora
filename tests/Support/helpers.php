<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Inventory\Contracts\StockInitialiser;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderAddress;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Pricing\Services\BreakdownCalculator;
use App\Modules\Shipping\Actions\CreateShipment;
use App\Modules\Shipping\Actions\HandOverShipment;
use App\Modules\Shipping\Actions\RecordCourierEvent;
use App\Modules\Shipping\Database\Seeders\CourierSeeder;
use App\Modules\Shipping\DTOs\CourierEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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

    /** A confirmed order (tee ৳650.00, mug ৳350.00, delivery ৳60, cash fee ৳20 for cash orders) with its stock held. $lines: [variant id => quantity]. */
    $t->order = function (array $lines, string $method = 'cod') use ($t, $system): object {
        $prices = [$t->tee => 65000, $t->mug => 35000];
        $calc = [];
        $newLines = [];
        foreach ($lines as $variantId => $qty) {
            $calc[] = ['variantId' => $variantId, 'quantity' => $qty, 'unitMinor' => $prices[$variantId], 'lineDiscountMinor' => 0, 'taxRateBp' => 0];
            $newLines[] = new NewOrderLine($variantId, 'physical', ['en' => $variantId === $t->tee ? 'Cotton Tee' : 'Mug', 'bn' => 'পণ্য'], $variantId === $t->tee ? 'TEE-1' : 'MUG-1');
        }
        $breakdown = (new BreakdownCalculator)->calculate($calc, false, 0, 6000, $method === 'cod' ? 2000 : 0);
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
