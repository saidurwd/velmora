<?php

declare(strict_types=1);

// Browser tests only, in the throw-away e2e database: one cash order of two demo products, sent in one parcel by the manual courier and marked "on the way",
// and the signed link a customer would be given to track it (written to storage/app/e2e-tracking-url.txt for the tracking test to open).
use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderAddress;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Pricing\Services\BreakdownCalculator;
use App\Modules\Shipping\Actions\CreateShipment;
use App\Modules\Shipping\Actions\HandOverShipment;
use App\Modules\Shipping\Actions\RecordManualShipmentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

$system = Actor::system('e2e');
// the manual status is written by a staff member (a real user row, as the parcel history points at it)
$staffUser = new User;
$staffUser->fill(['name' => 'E2E Warehouse', 'email' => 'e2e.warehouse@example.com', 'password' => 'E2e-Pass-1234!', 'locale' => 'en']);
$staffUser->forceFill(['account_type' => AccountType::Staff, 'status' => UserStatus::Active, 'email_verified_at' => now()])->save();
$staff = new Actor(ActorType::Staff, $staffUser->id, $staffUser->public_id, 'E2E Warehouse');
$variants = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')->join('stock_levels as l', 'l.variant_id', '=', 'v.id')
    ->where('p.product_type', 'physical')->where('l.on_hand', '>=', 5)->orderBy('v.id')->limit(2)->get(['v.id', 'v.sku'])->all();
if (count($variants) < 2) {
    throw new RuntimeException('the demo catalog has no two stocked physical products');
}

$lines = [];
$calc = [];
$newLines = [];
foreach ($variants as $i => $variant) {
    $lines[(int) $variant->id] = $i + 1;
    $calc[] = ['variantId' => (int) $variant->id, 'quantity' => $i + 1, 'unitMinor' => 65000, 'lineDiscountMinor' => 0, 'taxRateBp' => 0];
    $newLines[] = new NewOrderLine((int) $variant->id, 'physical', ['en' => 'Demo item '.($i + 1), 'bn' => 'ডেমো পণ্য '.($i + 1)], (string) $variant->sku);
}
$breakdown = (new BreakdownCalculator)->calculate($calc, false, 0, 6000, 2000);
$address = new NewOrderAddress('shipping', 'Rahim Uddin', '+8801898765432', 3, 30, 300, ['division' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'district' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'upazila' => ['en' => 'Dhanmondi', 'bn' => 'ধানমন্ডি']], 'House 5, Road 2', '1205', 'Dhanmondi', null);
$order = app(CreateOrder::class)->handle(new NewOrder(Actor::guest('e2e'), null, 'Rahim Uddin', '+8801898765432', null, OrderChannel::Web, 'en', 'cod', 'standard', $breakdown, $newLines, [$address]));
app(StockReservations::class)->holdForOrder($lines, $order->public_id);
app(TransitionOrder::class)->status($system, $order->id, OrderStatus::Confirmed, 'e2e');

$byItem = [];
foreach ($lines as $variantId => $qty) {
    $byItem[(int) DB::table('order_items')->where('order_id', $order->id)->where('variant_id', $variantId)->value('id')] = $qty;
}
$first = array_key_first($byItem);
$manual = (string) DB::table('courier_accounts')->where('courier', 'manual')->value('public_id');
$parcel = app(CreateShipment::class)->handle($system, $order->public_id, [$first => $byItem[$first]], $manual);   // one item is sent, the other is still being prepared
app(HandOverShipment::class)->handle($system, $parcel->public_id);
sleep(2); // the courier's events are told apart by the second
app(RecordManualShipmentStatus::class)->handle($staff, $parcel->public_id, 'in_transit', 'At the sorting centre');

file_put_contents(storage_path('app/e2e-tracking-url.txt'), URL::temporarySignedRoute('orders.track', now()->addDays(7), ['locale' => 'en', 'order' => $order->public_id]));
