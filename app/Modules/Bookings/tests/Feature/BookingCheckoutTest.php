<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\ConfirmBookingsForOrder;
use App\Modules\Bookings\Actions\ExpireStaleHolds;
use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Actions\ReleaseBookingsForOrder;
use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Bookings\Contracts\BookingHolds;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\PlaceOrderCommand;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Exceptions\CheckoutException;
use App\Modules\Orders\Services\CheckoutPricing;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Outbox\OutboxDispatcher;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    bookingsWorld($this);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->seed([PriceListSeeder::class, PaymentMethodSeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    app(SetRegularPrice::class)->handle($this->staff, $this->variant30, Money::of(50000));
    app(TransitionProduct::class)->publish($this->staff, Product::query()->whereHas('variants', fn ($q) => $q->whereKey($this->variant30))->firstOrFail());
    $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));
    app(SaveServiceDefinition::class)->handle($this->staff, 'SVC-30', 'at_provider', 60, 60, 15, true);
    app(SetWeeklyAvailability::class)->handle($this->staff, $this->tailor, [['weekday' => 2, 'start' => '09:00', 'end' => '12:00']]);
    $this->alice = CartOwner::guest(str_repeat('a', 40));
    $this->bob = CartOwner::guest(str_repeat('b', 40));
    $this->hold = fn (CartOwner $owner, string $local = '10:00') => app(HoldSlot::class)->handle($owner, 'SVC-30', $this->tailor->public_id, CarbonImmutable::parse("2026-12-01 {$local}", 'Asia/Dhaka')->utc()->format('Y-m-d H:i:s'));
    $this->command = fn (CartOwner $owner, string $pay = 'online') => new PlaceOrderCommand(
        actor: Actor::guest('checkout'), owner: $owner, userId: null, contactName: 'Rahim Uddin', contactPhone: '01712345678', contactEmail: null, address: null, shippingMethodCode: null, paymentMethodCode: $pay,
        expectedTotalMinor: app(CheckoutPricing::class)->quote(app(CartReader::class)->view($owner, 'en'), null, null, $pay, 'en')->breakdown->grandTotalMinor, placeToken: 'token-'.Str::random(12), locale: 'en',
    );
    $this->order = fn (CartOwner $owner) => app(PlaceOrder::class)->handle(($this->command)($owner));
    $this->row = fn (int $id) => DB::table('service_bookings')->where('id', $id)->first();
    $this->events = fn (int $id) => DB::table('booking_events')->where('booking_id', $id)->orderBy('id')->pluck('event')->all();
    $this->pay = function (int $orderId) {
        app(TransitionOrder::class)->status(Actor::system('t'), $orderId, OrderStatus::Confirmed, 'test');
        app(TransitionOrder::class)->payment(Actor::system('t'), $orderId, PaymentStatus::Paid, 'test');
        app(OutboxDispatcher::class)->dispatchDue();
    };
});

describe('checkout', function () {
    it('takes over the hold for the order line, keeps it for the payment window, and empties the cart', function () {
        $booking = ($this->hold)($this->alice);
        $order = ($this->order)($this->alice);

        $row = ($this->row)($booking->id);
        $item = DB::table('order_items')->where('order_id', $order->id)->first();
        expect($row->status)->toBe('held')->and((int) $row->order_item_id)->toBe((int) $item->id)->and($item->product_type)->toBe('service')
            ->and(CarbonImmutable::parse($row->hold_expires_at, 'UTC')->equalTo(now()->addMinutes(35)))->toBeTrue()
            ->and(($this->events)($booking->id))->toBe(['held', 'ordered'])->and(DB::table('cart_items')->count())->toBe(0);
    });

    it('refuses when the hold ran out, and writes nothing (the cart stays)', function () {
        $booking = ($this->hold)($this->alice);
        $command = ($this->command)($this->alice);
        $this->travel(16)->minutes();

        expect(fn () => app(PlaceOrder::class)->handle($command))->toThrow(CheckoutException::class, 'booking_lost');
        expect(DB::table('orders')->count())->toBe(0)->and(DB::table('cart_items')->count())->toBe(1)->and(($this->row)($booking->id)->order_item_id)->toBeNull();
    });

    it('refuses a hold that already belongs to an order, and a hold for another product', function () {
        $mine = ($this->hold)($this->alice);
        ($this->order)($this->alice);

        $bobs = ($this->hold)($this->bob, '11:00');
        DB::table('cart_items')->update(['hold_ref' => $mine->public_id]);   // bob's line pointed at alice's ordered booking
        expect(fn () => ($this->order)($this->bob))->toThrow(CheckoutException::class, 'booking_lost');

        $other = ($this->book)($this->room->id, $this->package60->id, 'held', '2026-12-01 06:00', 60);   // a hold on a different service
        DB::table('cart_items')->update(['hold_ref' => DB::table('service_bookings')->where('id', $other)->value('public_id')]);
        expect(fn () => ($this->order)($this->bob))->toThrow(CheckoutException::class, 'booking_lost');
        expect(DB::table('orders')->count())->toBe(1)->and(($this->row)($bobs->id)->order_item_id)->toBeNull()->and(($this->row)($other)->order_item_id)->toBeNull();
    });

    it('refuses cash on delivery for a booked service', function () {
        ($this->hold)($this->alice);

        expect(fn () => app(PlaceOrder::class)->handle(($this->command)($this->alice, 'cod')))->toThrow(CheckoutException::class, 'payment_method_invalid');
    });

    it('says why in both languages', function () {
        foreach (['en', 'bn'] as $locale) {
            expect(trans('orders::checkout.errors.booking_lost', [], $locale))->not->toBe('orders::checkout.errors.booking_lost');
        }
        expect(trans('orders::checkout.errors.booking_lost', [], 'bn'))->not->toBe(trans('orders::checkout.errors.booking_lost', [], 'en'));
    });
});

describe('payment', function () {
    it('confirms the booking once, however often the news arrives, and the held time stops running out', function () {
        $booking = ($this->hold)($this->alice);
        $order = ($this->order)($this->alice);
        ($this->pay)($order->id);

        $row = ($this->row)($booking->id);
        expect($row->status)->toBe('confirmed')->and($row->hold_expires_at)->toBeNull()->and(($this->events)($booking->id))->toBe(['held', 'ordered', 'confirmed']);
        expect(app(ConfirmBookingsForOrder::class)->handle($order->id))->toBe(0);
        $this->travel(2)->hours();
        expect(app(ExpireStaleHolds::class)->handle())->toBe(0)->and(($this->row)($booking->id)->status)->toBe('confirmed')->and(($this->events)($booking->id))->toBe(['held', 'ordered', 'confirmed']);
    });

    it('does not confirm anything for an order that is not paid, whatever asked', function () {
        $booking = ($this->hold)($this->alice);
        $order = ($this->order)($this->alice);

        expect(app(ConfirmBookingsForOrder::class)->handle($order->id))->toBe(0)->and(($this->row)($booking->id)->status)->toBe('held');
    });

    it('does not confirm a payment that came after the time was given up: it says so, alerts, and brings nothing back', function () {
        Log::spy();
        $booking = ($this->hold)($this->alice);
        $order = ($this->order)($this->alice);
        $this->travel(40)->minutes();
        expect(app(ExpireStaleHolds::class)->handle())->toBe(1);
        $taken = ($this->hold)($this->bob);   // somebody else now holds the very same time

        ($this->pay)($order->id);

        expect(($this->row)($booking->id)->status)->toBe('cancelled')->and(($this->events)($booking->id))->toBe(['held', 'ordered', 'hold_expired', 'payment_after_expiry'])
            ->and(($this->row)($taken->id)->status)->toBe('held');
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => $message === 'bookings.alert' && ($context['reason'] ?? null) === 'payment_after_expiry' && $context['order_id'] === $order->id)->once();
    });

    it('also refuses a late payment when the sweeper has not run yet', function () {
        Log::spy();
        $booking = ($this->hold)($this->alice);
        $order = ($this->order)($this->alice);
        $this->travel(40)->minutes();

        ($this->pay)($order->id);

        expect(($this->row)($booking->id)->status)->toBe('cancelled')->and(($this->events)($booking->id))->toBe(['held', 'ordered', 'hold_expired', 'payment_after_expiry']);
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => $message === 'bookings.alert')->once();
    });
});

describe('cancelling', function () {
    it('gives the held time back, once, and the time can be held again', function () {
        $booking = ($this->hold)($this->alice);
        $order = ($this->order)($this->alice);

        app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Cancelled, 'changed mind');
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();

        expect(($this->row)($booking->id)->status)->toBe('cancelled')->and(($this->events)($booking->id))->toBe(['held', 'ordered', 'order_cancelled']);
        expect(($this->hold)($this->bob)->status->value)->toBe('held');
    });

    it('leaves a booking that is already confirmed alone (ending a paid booking has its own rules)', function () {
        $booking = ($this->hold)($this->alice);
        $order = ($this->order)($this->alice);
        ($this->pay)($order->id);

        app(ReleaseBookingsForOrder::class)->handle($order->id);

        expect(($this->row)($booking->id)->status)->toBe('confirmed');
    });
});

describe('the contract', function () {
    it('claims nothing that is not a hold of this product, and is refused twice', function () {
        $booking = ($this->hold)($this->alice);
        $order = ($this->order)($this->alice);
        $itemId = (int) DB::table('order_items')->where('order_id', $order->id)->value('id');

        expect(fn () => app(BookingHolds::class)->claim($booking->public_id, $this->variant30, $itemId))->toThrow(DomainException::class, 'hold_lost');
        expect(fn () => app(BookingHolds::class)->claim('nonsense', $this->variant30, $itemId))->toThrow(DomainException::class, 'hold_lost');
    });
});
