<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Inventory\Contracts\StockInitialiser;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Inventory\Services\StockLedgerVerifier;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderAddress;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Pricing\Services\BreakdownCalculator;
use App\Modules\Shipping\Actions\BookShipment;
use App\Modules\Shipping\Actions\CancelShipment;
use App\Modules\Shipping\Actions\CreateShipment;
use App\Modules\Shipping\Actions\HandOverShipment;
use App\Modules\Shipping\Couriers\FakeCourier;
use App\Modules\Shipping\Database\Seeders\CourierSeeder;
use App\Modules\Shipping\Exceptions\ShipmentException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, CourierSeeder::class, StockLocationSeeder::class]);
    $this->staff = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Amina');
    $system = Actor::system('t');
    $category = app(SaveCategory::class)->handle($system, null, null, [new TranslationInput('en', 'General')]);

    $this->variant = function (string $sku, string $en, int $stock) use ($system, $category): int {
        $p = app(SaveProduct::class)->handle($system, null, new ProductData(ProductType::Physical, [new TranslationInput('en', $en), new TranslationInput('bn', $en)], categoryIds: [$category->id], defaultVariant: new VariantData($sku, trackInventory: true)));
        $id = (int) $p->variants()->value('id');
        app(StockInitialiser::class)->openingStock($system, $id, $stock);

        return $id;
    };
    $this->tee = ($this->variant)('TEE-1', 'Cotton Tee', 20);
    $this->mug = ($this->variant)('MUG-1', 'Mug', 20);

    /**
     * A confirmed order with real stock held. $lines: [variant id => quantity]; tee ৳650.00 each, mug ৳350.00 each, delivery ৳60, cash fee ৳20 (cash orders).
     * Returns the order row.
     */
    $this->order = function (array $lines, string $method = 'cod', bool $confirm = true, array $digital = []) use ($system): object {
        $prices = [$this->tee => 65000, $this->mug => 35000];
        $calc = [];
        $newLines = [];
        foreach ($lines as $variantId => $qty) {
            $calc[] = ['variantId' => $variantId, 'quantity' => $qty, 'unitMinor' => $prices[$variantId], 'lineDiscountMinor' => 0, 'taxRateBp' => 0];
            $newLines[] = new NewOrderLine($variantId, in_array($variantId, $digital, true) ? 'digital' : 'physical', ['en' => $variantId === $this->tee ? 'Cotton Tee' : 'Mug', 'bn' => 'পণ্য'], $variantId === $this->tee ? 'TEE-1' : 'MUG-1');
        }
        $breakdown = (new BreakdownCalculator)->calculate($calc, false, 0, 6000, $method === 'cod' ? 2000 : 0);
        $address = new NewOrderAddress('shipping', 'Rahim Uddin', '+8801712345678', 3, 30, 300, ['division' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'district' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'upazila' => ['en' => 'Dhanmondi', 'bn' => 'ধানমন্ডি']], 'House 5, Road 2', '1205', 'Dhanmondi', null);
        $order = app(CreateOrder::class)->handle(new NewOrder(Actor::guest('checkout'), null, 'Rahim Uddin', '+8801712345678', null, OrderChannel::Web, 'en', $method, 'standard', $breakdown, $newLines, [$address]));
        app(StockReservations::class)->holdForOrder($lines, $order->public_id);
        if ($confirm) {
            if ($method !== 'cod') {
                app(TransitionOrder::class)->payment($system, $order->id, PaymentStatus::Paid, 'test');
            }
            app(TransitionOrder::class)->status($system, $order->id, OrderStatus::Confirmed, 'test');
        }

        return DB::table('orders')->where('id', $order->id)->first();
    };
    $this->items = fn (int $orderId) => DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get();
    $this->itemOf = fn (int $orderId, int $variantId) => (int) DB::table('order_items')->where('order_id', $orderId)->where('variant_id', $variantId)->value('id');
    $this->ship = fn (object $order, array $byVariant, ?string $courier = null) => app(CreateShipment::class)->handle($this->staff, $order->public_id, collect($byVariant)->mapWithKeys(fn ($q, $v) => [($this->itemOf)($order->id, $v) => $q])->all(), $courier);
    $this->stock = fn (int $variantId) => DB::table('stock_levels')->where('variant_id', $variantId)->first(['on_hand', 'reserved']);
    $this->state = fn (int $orderId) => DB::table('orders')->where('id', $orderId)->first(['status', 'fulfillment_status', 'payment_status']);
    $this->refuses = fn (callable $call, string $reason) => expect($call)->toThrow(ShipmentException::class, $reason);
    $this->courier = fn () => app(FakeCourier::class);
    $this->fakeAccount = fn () => (string) DB::table('courier_accounts')->insertGetId(['public_id' => $id = strtolower((string) Str::ulid()), 'courier' => 'fake', 'label' => 'Fake', 'is_active' => 1, 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()]) && isset($id) ? $id : '';
});

describe('making a whole parcel', function () {
    it('counts it as shipped, takes the stock out for those quantities, and moves the order along', function () {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1]);

        $shipment = ($this->ship)($order, [$this->tee => 2, $this->mug => 1]);

        expect($shipment->status->value)->toBe('created')->and($shipment->courier)->toBe('manual')->and($shipment->order_id)->toBe($order->id)->and($shipment->items()->count())->toBe(2)
            ->and(($this->state)($order->id)->fulfillment_status)->toBe('fulfilled')->and(($this->state)($order->id)->status)->toBe('processing')
            ->and(($this->stock)($this->tee)->on_hand)->toBe(18)->and(($this->stock)($this->tee)->reserved)->toBe(0)->and(($this->stock)($this->mug)->on_hand)->toBe(19)
            ->and(DB::table('stock_movements')->where('ref_type', 'shipment')->where('ref_id', $shipment->public_id)->where('reason', 'shipment')->sum('delta'))->toEqual(-3)
            ->and(DB::table('order_items')->where('order_id', $order->id)->sum('fulfilled_qty'))->toEqual(3)
            ->and(DB::table('shipment_events')->where('shipment_id', $shipment->id)->count())->toBe(1)
            ->and(DB::table('audit_events')->where('action', 'shipment.created')->count())->toBe(1)
            ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
    });

    it('collects the whole order total in cash on the one parcel of a cash order', function () {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1]);

        $shipment = ($this->ship)($order, [$this->tee => 2, $this->mug => 1]);

        expect($shipment->cod_amount_minor)->toBe((int) $order->grand_total_minor)->and($shipment->cod_amount_minor)->toBe(2 * 65000 + 35000 + 6000 + 2000);
    });

    it('collects nothing on a parcel of a paid online order', function () {
        $order = ($this->order)([$this->tee => 1], 'online');

        $shipment = ($this->ship)($order, [$this->tee => 1]);

        expect($shipment->cod_amount_minor)->toBe(0)->and(($this->state)($order->id)->fulfillment_status)->toBe('fulfilled');
    });
});

describe('making part of an order', function () {
    it('takes stock only for the shipped quantities and leaves the order partly fulfilled', function () {
        $order = ($this->order)([$this->tee => 3, $this->mug => 2]);

        $first = ($this->ship)($order, [$this->tee => 2]);

        expect(($this->state)($order->id)->fulfillment_status)->toBe('partially_fulfilled')->and(($this->state)($order->id)->status)->toBe('processing')
            ->and(($this->stock)($this->tee))->toMatchArray(['on_hand' => 18, 'reserved' => 1])->and(($this->stock)($this->mug))->toMatchArray(['on_hand' => 20, 'reserved' => 2])
            ->and(DB::table('shipment_items')->where('shipment_id', $first->id)->sum('quantity'))->toEqual(2)
            ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
    });

    it('finishes the order with the second parcel, and the cash of the parcels adds up to the total exactly', function () {
        $order = ($this->order)([$this->tee => 3, $this->mug => 2]);

        $first = ($this->ship)($order, [$this->tee => 2, $this->mug => 1]);
        $second = ($this->ship)($order, [$this->tee => 1, $this->mug => 1]);

        expect(($this->state)($order->id)->fulfillment_status)->toBe('fulfilled')->and($first->cod_amount_minor)->toBe(2 * 65000 + 35000)   // its lines' share only
            ->and($second->cod_amount_minor)->toBe((int) $order->grand_total_minor - $first->cod_amount_minor)                           // the last parcel takes delivery, the fee and any rounding
            ->and($first->cod_amount_minor + $second->cod_amount_minor)->toBe((int) $order->grand_total_minor)
            ->and(($this->stock)($this->tee))->toMatchArray(['on_hand' => 17, 'reserved' => 0])->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
    });

    it('never lets more be sent than was ordered, across parcels', function () {
        $order = ($this->order)([$this->tee => 3]);
        ($this->ship)($order, [$this->tee => 2]);

        ($this->refuses)(fn () => ($this->ship)($order, [$this->tee => 2]), 'quantity_exceeds_remaining');
        ($this->ship)($order, [$this->tee => 1]);
        ($this->refuses)(fn () => ($this->ship)($order, [$this->tee => 1]), 'quantity_exceeds_remaining');

        expect(DB::table('order_items')->where('order_id', $order->id)->value('fulfilled_qty'))->toBe(3)->and(DB::table('shipments')->count())->toBe(2)->and(($this->stock)($this->tee)->on_hand)->toBe(17);
    });
});

describe('what is refused', function () {
    it('refuses an order that is not ready, and leaves everything as it was', function () {
        $pending = ($this->order)([$this->tee => 1], 'cod', false);
        ($this->refuses)(fn () => ($this->ship)($pending, [$this->tee => 1]), 'order_not_confirmed');

        $unpaid = ($this->order)([$this->tee => 1], 'online', false);
        app(TransitionOrder::class)->status(Actor::system('t'), $unpaid->id, OrderStatus::Confirmed, 'test');
        ($this->refuses)(fn () => ($this->ship)($unpaid, [$this->tee => 1]), 'order_not_paid');

        $review = ($this->order)([$this->tee => 1]);
        DB::table('orders')->where('id', $review->id)->update(['needs_review' => 1]);
        ($this->refuses)(fn () => ($this->ship)($review, [$this->tee => 1]), 'order_under_review');

        $cancelled = ($this->order)([$this->tee => 1]);
        app(TransitionOrder::class)->status(Actor::system('t'), $cancelled->id, OrderStatus::Cancelled, 'test');
        ($this->refuses)(fn () => ($this->ship)($cancelled, [$this->tee => 1]), 'order_cancelled');

        expect(DB::table('shipments')->count())->toBe(0)->and(DB::table('stock_movements')->where('reason', 'shipment')->count())->toBe(0);
    });

    it('refuses empty, zero, unknown, foreign and digital lines', function () {
        $order = ($this->order)([$this->tee => 1]);
        $other = ($this->order)([$this->mug => 1]);
        $tee = ($this->itemOf)($order->id, $this->tee);

        ($this->refuses)(fn () => app(CreateShipment::class)->handle($this->staff, $order->public_id, []), 'no_lines');
        ($this->refuses)(fn () => app(CreateShipment::class)->handle($this->staff, $order->public_id, [$tee => 0]), 'quantity_invalid');
        ($this->refuses)(fn () => app(CreateShipment::class)->handle($this->staff, $order->public_id, [999999 => 1]), 'line_not_in_order');
        ($this->refuses)(fn () => app(CreateShipment::class)->handle($this->staff, $order->public_id, [($this->itemOf)($other->id, $this->mug) => 1]), 'line_not_in_order');
        ($this->refuses)(fn () => app(CreateShipment::class)->handle($this->staff, '01HZZZZZZZZZZZZZZZZZZZZZZZ', [$tee => 1]), 'order_not_found');
    });

    it('does not post a digital line, and still ships the physical ones beside it', function () {
        $order = ($this->order)([$this->tee => 1, $this->mug => 1], 'online', true, [$this->mug]);

        ($this->refuses)(fn () => ($this->ship)($order, [$this->mug => 1]), 'line_not_shippable');
        $shipment = ($this->ship)($order, [$this->tee => 1]);

        expect($shipment->items()->count())->toBe(1)->and(($this->state)($order->id)->fulfillment_status)->toBe('fulfilled');   // the digital line is delivered another way
    });

    it('refuses a courier that does not exist or is switched off', function () {
        $order = ($this->order)([$this->tee => 1]);
        DB::table('courier_accounts')->update(['is_active' => 0]);

        ($this->refuses)(fn () => ($this->ship)($order, [$this->tee => 1]), 'courier_unavailable');
        ($this->refuses)(fn () => ($this->ship)($order, [$this->tee => 1], '01HZZZZZZZZZZZZZZZZZZZZZZZ'), 'courier_unavailable');
    });

    it('rolls back everything when the order does not hold the stock', function () {
        $order = ($this->order)([$this->tee => 2]);
        app(StockReservations::class)->releaseForOrder($order->public_id);   // the hold is gone (a bug elsewhere): shipping must not invent stock

        ($this->refuses)(fn () => ($this->ship)($order, [$this->tee => 2]), 'stock_not_held');

        expect(DB::table('shipments')->count())->toBe(0)->and(DB::table('order_items')->where('order_id', $order->id)->value('fulfilled_qty'))->toBe(0)->and(($this->state)($order->id)->fulfillment_status)->toBe('unfulfilled')->and(($this->stock)($this->tee)->on_hand)->toBe(20);
    });
});

describe('cancelling a parcel before it leaves', function () {
    it('puts the stock back into the order\'s hold, the counts down and the order back, with a ledger row for each', function () {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1]);
        $shipment = ($this->ship)($order, [$this->tee => 2, $this->mug => 1]);

        $cancelled = app(CancelShipment::class)->handle($this->staff, $shipment->public_id, 'Wrong item packed');

        expect($cancelled->status->value)->toBe('cancelled')->and(($this->state)($order->id)->fulfillment_status)->toBe('unfulfilled')
            ->and(($this->stock)($this->tee))->toMatchArray(['on_hand' => 20, 'reserved' => 2])->and(($this->stock)($this->mug))->toMatchArray(['on_hand' => 20, 'reserved' => 1])
            ->and(DB::table('order_items')->where('order_id', $order->id)->sum('fulfilled_qty'))->toEqual(0)
            ->and(DB::table('stock_movements')->where('ref_id', $shipment->public_id)->where('reason', 'shipment_cancelled')->sum('delta'))->toEqual(3)
            ->and(DB::table('shipment_events')->where('shipment_id', $shipment->id)->orderBy('id')->pluck('internal_status')->all())->toBe(['created', 'cancelled'])
            ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
    });

    it('lets the same lines go in a new parcel afterwards, and the cancelled parcel\'s cash is free again', function () {
        $order = ($this->order)([$this->tee => 2]);
        $first = ($this->ship)($order, [$this->tee => 2]);
        app(CancelShipment::class)->handle($this->staff, $first->public_id, 'Wrong box');

        $second = ($this->ship)($order, [$this->tee => 2]);

        expect($second->cod_amount_minor)->toBe((int) $order->grand_total_minor)->and(($this->state)($order->id)->fulfillment_status)->toBe('fulfilled')->and(($this->stock)($this->tee)->on_hand)->toBe(18)->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
    });

    it('brings a partly sent order back to its earlier state', function () {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1]);
        ($this->ship)($order, [$this->tee => 2]);
        $second = ($this->ship)($order, [$this->mug => 1]);
        expect(($this->state)($order->id)->fulfillment_status)->toBe('fulfilled');

        app(CancelShipment::class)->handle($this->staff, $second->public_id, 'Customer asked to wait');

        expect(($this->state)($order->id)->fulfillment_status)->toBe('partially_fulfilled')->and(($this->stock)($this->mug))->toMatchArray(['on_hand' => 20, 'reserved' => 1]);
    });

    it('refuses once the parcel has left, twice, and without a reason', function () {
        $order = ($this->order)([$this->tee => 1, $this->mug => 1]);
        $left = ($this->ship)($order, [$this->tee => 1]);
        app(HandOverShipment::class)->handle($this->staff, $left->public_id);
        $other = ($this->ship)($order, [$this->mug => 1]);

        ($this->refuses)(fn () => app(CancelShipment::class)->handle($this->staff, $left->public_id, 'Too late'), 'cannot_cancel_after_handover');
        ($this->refuses)(fn () => app(CancelShipment::class)->handle($this->staff, $other->public_id, '  '), 'reason_required');
        app(CancelShipment::class)->handle($this->staff, $other->public_id, 'Changed my mind');
        ($this->refuses)(fn () => app(CancelShipment::class)->handle($this->staff, $other->public_id, 'Again'), 'shipment_cancelled');
        ($this->refuses)(fn () => app(CancelShipment::class)->handle($this->staff, '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'x'), 'shipment_not_found');

        expect(($this->stock)($this->tee)->on_hand)->toBe(19)->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
    });

    it('stops an order from being cancelled once a parcel exists, and lets it be cancelled after the parcel is cancelled', function () {
        $order = ($this->order)([$this->tee => 1]);
        $shipment = ($this->ship)($order, [$this->tee => 1]);

        expect(fn () => app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Cancelled, 'Changed my mind'))->toThrow(OrderException::class, 'cancel_after_shipping');

        app(CancelShipment::class)->handle($this->staff, $shipment->public_id, 'Wrong parcel');
        app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Cancelled, 'Changed my mind');

        expect(($this->state)($order->id)->status)->toBe('cancelled')->and(($this->stock)($this->tee))->toMatchArray(['on_hand' => 20, 'reserved' => 0])->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
    });
});

describe('handing the parcel over', function () {
    it('marks it handed over with the time, once', function () {
        $order = ($this->order)([$this->tee => 1]);
        $shipment = ($this->ship)($order, [$this->tee => 1]);

        $handed = app(HandOverShipment::class)->handle($this->staff, $shipment->public_id);

        expect($handed->status->value)->toBe('handed_over')->and($handed->handed_over_at)->not->toBeNull()->and(DB::table('shipment_events')->where('shipment_id', $shipment->id)->orderBy('id')->pluck('internal_status')->all())->toBe(['created', 'handed_over']);
        ($this->refuses)(fn () => app(HandOverShipment::class)->handle($this->staff, $shipment->public_id), 'already_handed_over');
        ($this->refuses)(fn () => app(HandOverShipment::class)->handle($this->staff, '01HZZZZZZZZZZZZZZZZZZZZZZZ'), 'shipment_not_found');
    });

    it('refuses a cancelled parcel', function () {
        $order = ($this->order)([$this->tee => 1]);
        $shipment = ($this->ship)($order, [$this->tee => 1]);
        app(CancelShipment::class)->handle($this->staff, $shipment->public_id, 'No');

        ($this->refuses)(fn () => app(HandOverShipment::class)->handle($this->staff, $shipment->public_id), 'shipment_cancelled');
    });
});

describe('when the courier cannot be reached', function () {
    it('keeps the parcel and its stock, and books it later', function () {
        $account = ($this->fakeAccount)();
        $order = ($this->order)([$this->tee => 2]);
        ($this->courier)()->failNext('create');

        $shipment = ($this->ship)($order, [$this->tee => 2], $account);

        expect($shipment->status->value)->toBe('created')->and($shipment->courier_consignment_id)->toBeNull()->and(($this->stock)($this->tee)->on_hand)->toBe(18)->and(($this->state)($order->id)->fulfillment_status)->toBe('fulfilled')
            ->and(DB::table('shipment_events')->where('shipment_id', $shipment->id)->pluck('provider_status')->all())->toContain('booking_failed');
        ($this->refuses)(fn () => app(HandOverShipment::class)->handle($this->staff, $shipment->public_id), 'booking_missing');

        $booked = app(BookShipment::class)->handle($this->staff, $shipment->public_id);

        expect($booked->courier_consignment_id)->toStartWith('FAKE-')->and($booked->tracking_code)->toStartWith('TRKFAKE-')->and(app(HandOverShipment::class)->handle($this->staff, $shipment->public_id)->status->value)->toBe('handed_over');
    });

    it('books at once when the courier answers, sending it only what it needs', function () {
        $account = ($this->fakeAccount)();
        $order = ($this->order)([$this->tee => 2]);

        $shipment = ($this->ship)($order, [$this->tee => 2], $account);

        $sent = ($this->courier)()->booked[0];
        expect($shipment->courier_consignment_id)->not->toBeNull()->and($sent->recipientName)->toBe('Rahim Uddin')->and($sent->orderNumber)->toBe($order->order_number)->and($sent->codAmountMinor)->toBe($shipment->cod_amount_minor)->and($sent->itemCount)->toBe(2)->and($sent->district)->toBe('Dhaka');
        expect(json_encode($sent))->not->toContain('@');   // no email, nothing the courier does not need
    });

    it('books a parcel only once even when pressed twice', function () {
        $account = ($this->fakeAccount)();
        $order = ($this->order)([$this->tee => 1]);
        $shipment = ($this->ship)($order, [$this->tee => 1], $account);
        $id = $shipment->courier_consignment_id;

        $again = app(BookShipment::class)->handle($this->staff, $shipment->public_id);

        expect($again->courier_consignment_id)->toBe($id)->and(count(($this->courier)()->booked))->toBe(1);
    });

    it('tells the courier about a cancellation first, and stops if the courier refuses', function () {
        $account = ($this->fakeAccount)();
        $order = ($this->order)([$this->tee => 1]);
        $shipment = ($this->ship)($order, [$this->tee => 1], $account);
        ($this->courier)()->failNext('cancel');

        ($this->refuses)(fn () => app(CancelShipment::class)->handle($this->staff, $shipment->public_id, 'Wrong item'), 'courier_unreachable');
        expect(DB::table('shipments')->where('id', $shipment->id)->value('status'))->toBe('created')->and(($this->stock)($this->tee)->on_hand)->toBe(19);

        app(CancelShipment::class)->handle($this->staff, $shipment->public_id, 'Wrong item');
        expect(($this->courier)()->cancelled)->toBe([$shipment->fresh()->courier_consignment_id])->and(($this->stock)($this->tee)->on_hand)->toBe(20);
    });
});
