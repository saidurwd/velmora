<?php

declare(strict_types=1);

// Browser tests only, in the throw-away e2e database (never velmora or velmora_test): what the Phase 8 pages need to show.
//  - a digital e-book with a file, and a digital product with licence keys, both paid for by the demo customer (their library), and the e-book also paid for by a guest
//    (the signed link a guest would be sent is written to storage/app/e2e-guest-library-url.txt);
//  - a bookable service with a person, weekly hours and the shop's default cancellation policy, and three paid, confirmed bookings: two for the demo customer
//    (one to look at and move, one to cancel) and one for a guest (storage/app/e2e-guest-bookings-url.txt).
use App\Modules\Bookings\Actions\ClaimBookingHold;
use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SaveServicePackage;
use App\Modules\Bookings\Actions\SaveServiceResource;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Bookings\Models\ServicePackage;
use App\Modules\Bookings\Services\SlotSearch;
use App\Modules\Cart\Contracts\CartWriter;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\DigitalProducts\Actions\ImportLicenseKeys;
use App\Modules\DigitalProducts\Actions\UploadDigitalAsset;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Outbox\OutboxDispatcher;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Services\BreakdownCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

$system = Actor::system('e2e');
$customer = User::query()->where('email', 'demo.customer@example.com')->firstOrFail();
$category = (int) DB::table('categories')->orderBy('id')->value('id');

$make = function (string $sku, string $en, string $bn, ProductType $type, int $taka) use ($system, $category): int {
    $product = app(SaveProduct::class)->handle($system, null, new ProductData($type, [new TranslationInput('en', $en), new TranslationInput('bn', $bn)], categoryIds: [$category], defaultVariant: new VariantData($sku)));
    $variantId = (int) $product->variants()->value('id');
    app(SetRegularPrice::class)->handle($system, $variantId, Money::of($taka * 100));
    app(TransitionProduct::class)->publish($system, $product);

    return $variantId;
};
$ebook = $make('E2E-EBOOK', 'E2E Cooking E-book', 'ই২ই রান্নার ই-বুক', ProductType::Digital, 250);
$keys = $make('E2E-KEYS', 'E2E Photo Editor', 'ই২ই ফটো এডিটর', ProductType::Digital, 900);
$service = $make('E2E-FITTING', 'E2E Tailor Fitting', 'ই২ই দর্জির মাপ', ProductType::Service, 500);

$file = tempnam(sys_get_temp_dir(), 'e2e');
file_put_contents($file, "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\n");
app(UploadDigitalAsset::class)->handle($system, 'E2E-EBOOK', $file, 'E2E Cooking.pdf');
app(ImportLicenseKeys::class)->handle($system, 'E2E-KEYS', "E2E-AAAA-1111\nE2E-BBBB-2222\nE2E-CCCC-3333");

/** A paid order of the given lines [variantId => type], for a user or (null) a guest. */
$order = function (?User $user, array $lines, string $phone, string $name, bool $pay = true) use ($system) {
    $calc = [];
    $newLines = [];
    foreach ($lines as $variantId => $type) {
        $calc[] = ['variantId' => $variantId, 'quantity' => 1, 'unitMinor' => 50000, 'lineDiscountMinor' => 0, 'taxRateBp' => 0];
        $newLines[] = new NewOrderLine($variantId, $type, ['en' => $name, 'bn' => $name], 'E2E-'.$variantId);
    }
    $order = app(CreateOrder::class)->handle(new NewOrder(Actor::guest('e2e'), $user?->id, 'Demo Customer', $phone, null, OrderChannel::Web, 'en', 'online', null, (new BreakdownCalculator)->calculate($calc, false, 0, 0, 0), $newLines, []));
    app(TransitionOrder::class)->status($system, $order->id, OrderStatus::Confirmed, 'e2e');
    if ($pay) {
        app(TransitionOrder::class)->payment($system, $order->id, PaymentStatus::Paid, 'e2e');
    }

    return $order;
};
$order($customer, [$ebook => 'digital', $keys => 'digital'], '+8801712345678', 'E2E downloads');
$guestDigital = $order(null, [$ebook => 'digital'], '+8801700000001', 'E2E guest download');

// the service: one person, working every day, under the shop's default cancellation policy
app(SaveServiceDefinition::class)->handle($system, 'E2E-FITTING', 'at_provider', 60, 60, 15, true);
app(SaveServicePackage::class)->handle($system, 'E2E-FITTING', 30, 0, 10, true);
$tailor = app(SaveServiceResource::class)->handle($system, null, 'E2E Tailor', 'staff', true, ['E2E-FITTING']);
app(SetWeeklyAvailability::class)->handle($system, $tailor, array_map(fn (int $d) => ['weekday' => $d, 'start' => '09:00', 'end' => '17:00'], [1, 2, 3, 4, 5, 6, 7]));

$package = ServicePackage::query()->where('variant_id', $service)->firstOrFail();
$day = CarbonImmutable::now(config('bookings.timezone'))->addDays(4)->format('Y-m-d');
$slots = app(SlotSearch::class)->forPackage($package, $day, $day);
if (count($slots) < 6) {
    throw new RuntimeException('no free times for the demo service');
}

/** A confirmed booking: the visitor's hold is taken over by an order line and then paid, as checkout does it. */
$book = function (CartOwner $owner, ?User $user, object $slot, string $phone) use ($system, $order, $service) {
    $hold = app(HoldSlot::class)->handle($owner, 'E2E-FITTING', $slot->resourcePublicId, $slot->startsAt->format('Y-m-d H:i:s'));
    $paid = $order($user, [$service => 'service'], $phone, 'E2E fitting', false);
    $item = (int) DB::table('order_items')->where('order_id', $paid->id)->value('id');
    app(ClaimBookingHold::class)->claim($hold->public_id, $service, $item);   // first the time is taken over by the order ...
    app(TransitionOrder::class)->payment($system, $paid->id, PaymentStatus::Paid, 'e2e');   // ... then the payment confirms it

    return $paid;
};
$book(CartOwner::user($customer->id), $customer, $slots[0], '+8801712345678');   // to look at and move
$book(CartOwner::user($customer->id), $customer, $slots[4], '+8801712345678');   // to cancel
$guestBooking = $book(CartOwner::guest(Str::random(40)), null, $slots[8] ?? $slots[5], '+8801700000002');
// checkout empties the cart once the order is placed; the seed takes the same step so the demo customer starts with an empty cart
app(CartWriter::class)->clear(CartOwner::user($customer->id));
app(OutboxDispatcher::class)->dispatchDue();   // the downloads and keys are made, the bookings confirmed

file_put_contents(storage_path('app/e2e-guest-library-url.txt'), URL::temporarySignedRoute('digital.guest.library', now()->addDays(7), ['locale' => 'en', 'order' => $guestDigital->public_id]));
file_put_contents(storage_path('app/e2e-guest-bookings-url.txt'), URL::temporarySignedRoute('bookings.guest', now()->addDays(7), ['locale' => 'en', 'order' => $guestBooking->public_id]));
