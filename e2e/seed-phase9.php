<?php

declare(strict_types=1);

// Browser tests only, in the throw-away e2e database: what the Phase 9 customer pages need to show, for the demo customer.
//  - a cash order of two physical products, sent in one parcel, delivered, paid and completed (so there is something to buy again, send back and review);
//    one of its lines already has a review, the other does not (its form is open);
//  - a wishlist item, a support ticket the shop has answered, and marketing yes for email.
// The ids the browser tests need are written to storage/app/e2e-phase9.json.
use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Engagement\Actions\AddToWishlist;
use App\Modules\Engagement\Actions\OpenSupportTicket;
use App\Modules\Engagement\Actions\StaffReplyToSupportTicket;
use App\Modules\Engagement\Actions\SubmitReview;
use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Notifications\Actions\SaveMessagePreferences;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderAddress;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\Enums\FulfillmentStatus;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Pricing\Services\BreakdownCalculator;
use App\Modules\Shipping\Actions\CreateShipment;
use App\Modules\Shipping\Actions\HandOverShipment;
use App\Modules\Shipping\Actions\RecordManualShipmentStatus;
use Illuminate\Support\Facades\DB;

$system = Actor::system('e2e');
$customer = User::query()->where('email', 'demo.customer@example.com')->firstOrFail();
// a customer who signed up has a profile; the plain demo account did not get one
app(CustomerProfiles::class)->ensureForUser($customer->id, 'Demo Customer', 'demo.customer@example.com', null);
$staffUser = User::query()->where('email', 'e2e.warehouse@example.com')->first();
if ($staffUser === null) {
    $staffUser = new User;
    $staffUser->fill(['name' => 'E2E Warehouse', 'email' => 'e2e.warehouse@example.com', 'password' => 'E2e-Pass-1234!', 'locale' => 'en']);
    $staffUser->forceFill(['account_type' => AccountType::Staff, 'status' => UserStatus::Active, 'email_verified_at' => now()])->save();
}
$staff = new Actor(ActorType::Staff, $staffUser->id, $staffUser->public_id, 'E2E Warehouse');

$variants = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')->join('stock_levels as l', 'l.variant_id', '=', 'v.id')
    ->where('p.product_type', 'physical')->where('l.on_hand', '>=', 20)->orderByDesc('v.id')->limit(2)->get(['v.id', 'v.sku', 'v.public_id'])->all();
if (count($variants) < 2) {
    throw new RuntimeException('the demo catalog has no two stocked physical products');
}

$lines = [];
$calc = [];
$newLines = [];
foreach ($variants as $i => $variant) {
    $lines[(int) $variant->id] = 1;
    $calc[] = ['variantId' => (int) $variant->id, 'quantity' => 1, 'unitMinor' => 65000, 'lineDiscountMinor' => 0, 'taxRateBp' => 0];
    $newLines[] = new NewOrderLine((int) $variant->id, 'physical', ['en' => 'Demo item '.($i + 1), 'bn' => 'ডেমো পণ্য '.($i + 1)], (string) $variant->sku);
}
$breakdown = (new BreakdownCalculator)->calculate($calc, false, 0, 6000, 2000);
$address = new NewOrderAddress('shipping', 'Demo Customer', '+8801712345678', 3, 30, 300, ['division' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'district' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'upazila' => ['en' => 'Dhanmondi', 'bn' => 'ধানমন্ডি']], 'House 5, Road 3', null, 'Dhanmondi');
$order = app(CreateOrder::class)->handle(new NewOrder(Actor::guest('e2e'), $customer->id, 'Demo Customer', '+8801712345678', 'demo.customer@example.com', OrderChannel::Web, 'en', 'cod', 'standard', $breakdown, $newLines, [$address]));
app(StockReservations::class)->holdForOrder($lines, $order->public_id);
$move = app(TransitionOrder::class);
$move->status($system, $order->id, OrderStatus::Confirmed, 'e2e');

$byItem = [];
foreach ($lines as $variantId => $qty) {
    $byItem[(int) DB::table('order_items')->where('order_id', $order->id)->where('variant_id', $variantId)->value('id')] = $qty;
}
$manual = (string) DB::table('courier_accounts')->where('courier', 'manual')->value('public_id');
$parcel = app(CreateShipment::class)->handle($system, $order->public_id, $byItem, $manual);
app(HandOverShipment::class)->handle($system, $parcel->public_id);
sleep(2);   // the courier's events are told apart by the second
app(RecordManualShipmentStatus::class)->handle($staff, $parcel->public_id, 'in_transit', 'At the sorting centre');
sleep(2);
app(RecordManualShipmentStatus::class)->handle($staff, $parcel->public_id, 'delivered', 'Handed to the customer');
try {
    $move->payment($system, $order->id, PaymentStatus::Paid, 'e2e');   // a cash order is usually marked paid when it is delivered
} catch (Throwable) {
    // already paid
}
if (DB::table('orders')->where('id', $order->id)->value('status') !== OrderStatus::Completed->value) {
    foreach ([[OrderStatus::Processing, null], [null, FulfillmentStatus::Fulfilled], [OrderStatus::Completed, null]] as [$status, $fulfilment]) {
        try {
            $status !== null ? $move->status($system, $order->id, $status, 'e2e') : $move->fulfillment($system, $order->id, $fulfilment, 'e2e');
        } catch (Throwable) {
            // delivery may already have moved the order past this step
        }
    }
}

$reviewed = $variants[0];
$open = $variants[1];
app(SubmitReview::class)->handle($customer->id, $order->public_id, (string) $reviewed->public_id, 5, 'Good value', 'Arrived on time and exactly as described, would order again.');
app(AddToWishlist::class)->handle($customer->id, (string) DB::table('product_variants')->where('id', '<>', $reviewed->id)->where('id', '<>', $open->id)->orderBy('id')->value('public_id'), 'en');
app(SaveMessagePreferences::class)->handle($customer->id, ['email' => true, 'sms' => false], '22:00', '07:00');

$ticket = app(OpenSupportTicket::class)->handle($customer->id, 'en', 'Where is my invoice?', 'order', $order->public_id, 'Could you send me a copy of the receipt for my last order please.');
app(StaffReplyToSupportTicket::class)->handle($staff, $ticket, 'You can open the receipt from the order page; tell us if it does not work.', false);

file_put_contents(storage_path('app/e2e-phase9.json'), json_encode(['order' => $order->public_id, 'openVariant' => (string) $open->public_id, 'ticket' => $ticket], JSON_THROW_ON_ERROR));
