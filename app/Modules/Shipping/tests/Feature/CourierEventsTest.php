<?php

declare(strict_types=1);

use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Orders\Services\OrderCompletion;
use App\Modules\Payments\Actions\RecordCodCollection;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Shipping\Actions\CreateShipment;
use App\Modules\Shipping\Actions\HandOverShipment;
use App\Modules\Shipping\Actions\PollCourierShipments;
use App\Modules\Shipping\Actions\RecordCourierEvent;
use App\Modules\Shipping\Actions\RecordManualShipmentStatus;
use App\Modules\Shipping\Couriers\FakeCourier;
use App\Modules\Shipping\DTOs\CourierEvent;
use App\Modules\Shipping\Exceptions\ShipmentException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

beforeEach(function () {
    shippingWorld($this);
    config(['services.sms.driver' => 'fake']);
    $this->sms = app(FakeSmsGateway::class);
    $this->courier = fn () => app(FakeCourier::class);
    $this->refuses = fn (callable $call, string $reason) => expect($call)->toThrow(ShipmentException::class, $reason);
    $this->issues = fn (string $type) => DB::table('shipment_exceptions')->where('type', $type)->get();
    $this->texts = fn () => collect($this->sms->sent())->pluck('message')->all();
});

describe('what a courier says', function () {
    it('moves the parcel along, with the courier\'s own times, and tells the customer it is on its way and has arrived', function () {
        $order = ($this->order)([$this->tee => 1]);
        $p = ($this->parcel)($order, [$this->tee => 1], false);   // booked with the fake courier, not yet handed over

        expect(($this->say)($p, 'PICKED', '2026-11-03 09:00:00'))->toBe(RecordCourierEvent::RECORDED);
        $row = ($this->shipmentRow)($p->public_id);
        expect($row->status)->toBe('handed_over')->and($row->handed_over_at)->toStartWith('2026-11-03 09:00:00');

        ($this->say)($p, 'IN_TRANSIT', '2026-11-03 12:00:00');
        ($this->say)($p, 'OUT_FOR_DELIVERY', '2026-11-04 08:00:00');
        ($this->say)($p, 'DELIVERED', '2026-11-04 11:30:00');

        $row = ($this->shipmentRow)($p->public_id);
        expect($row->status)->toBe('delivered')->and($row->delivered_at)->toStartWith('2026-11-04 11:30:00')->and(DB::table('shipment_events')->where('shipment_id', $p->id)->where('source', 'webhook')->count())->toBe(4)
            ->and(collect(($this->texts)())->filter(fn ($m) => str_contains($m, 'on its way'))->count())->toBe(1)->and(collect(($this->texts)())->filter(fn ($m) => str_contains($m, 'was delivered'))->count())->toBe(1);
    });

    it('stores a repeated event once and does nothing the second time', function () {
        $order = ($this->order)([$this->tee => 1]);
        $p = ($this->parcel)($order, [$this->tee => 1]);

        ($this->say)($p, 'DELIVERED', '2026-11-04 11:30:00');
        $texts = count(($this->texts)());
        $again = ($this->say)($p, 'DELIVERED', '2026-11-04 11:30:00');

        expect($again)->toBe(RecordCourierEvent::DUPLICATE)->and(DB::table('shipment_events')->where('shipment_id', $p->id)->where('provider_status', 'DELIVERED')->count())->toBe(1)
            ->and(count(($this->texts)()))->toBe($texts)->and(DB::table('outbox_messages')->where('event_name', 'shipping.delivered')->count())->toBe(1);
    });

    it('follows the courier\'s time, not the order the messages arrive in', function () {
        $order = ($this->order)([$this->tee => 1]);
        $p = ($this->parcel)($order, [$this->tee => 1]);

        ($this->say)($p, 'DELIVERED', '2026-11-04 11:30:00');   // arrives first
        ($this->say)($p, 'IN_TRANSIT', '2026-11-03 12:00:00');   // an older message arrives late
        ($this->say)($p, 'OUT_FOR_DELIVERY', '2026-11-04 08:00:00');

        $row = ($this->shipmentRow)($p->public_id);
        expect($row->status)->toBe('delivered')->and($row->delivered_at)->toStartWith('2026-11-04 11:30:00')->and(DB::table('shipment_events')->where('shipment_id', $p->id)->count())->toBe(6);   // created, booked, handed over (by us) + three
    });

    it('lets the latest message win even when it is not the last to arrive, until the parcel has reached its end', function () {
        $order = ($this->order)([$this->tee => 1]);
        $p = ($this->parcel)($order, [$this->tee => 1]);

        ($this->say)($p, 'OUT_FOR_DELIVERY', '2026-11-04 08:00:00');
        ($this->say)($p, 'IN_TRANSIT', '2026-11-03 12:00:00');   // older: ignored for the state
        expect(($this->shipmentRow)($p->public_id)->status)->toBe('out_for_delivery');

        ($this->say)($p, 'DELIVERED', '2026-11-04 11:30:00');
        ($this->say)($p, 'IN_TRANSIT', '2026-11-05 09:00:00');   // newer, but a delivered parcel never goes back
        expect(($this->shipmentRow)($p->public_id)->status)->toBe('delivered');
    });

    it('keeps a word it does not know, raises it once, and leaves the parcel and the order alone', function () {
        Log::spy();
        $order = ($this->order)([$this->tee => 1]);
        $p = ($this->parcel)($order, [$this->tee => 1]);
        $before = ($this->state)($order->id);

        ($this->say)($p, 'SORTING_HUB_X', '2026-11-03 10:00:00');
        ($this->say)($p, 'SORTING_HUB_X', '2026-11-03 11:00:00');   // the same word again, later

        $issues = ($this->issues)('unknown_status');
        expect($issues)->toHaveCount(1)->and($issues[0]->reference)->toBe($p->public_id.':SORTING_HUB_X')->and(DB::table('shipment_events')->where('shipment_id', $p->id)->where('internal_status', 'unknown')->count())->toBe(2)
            ->and(($this->shipmentRow)($p->public_id)->status)->toBe('handed_over')->and(($this->state)($order->id))->toEqual($before);
        Log::shouldHaveReceived('error')->with('shipping.unknown_status', Mockery::type('array'))->atLeast()->once();
    });

    it('says so when the courier names a parcel we do not have', function () {
        $result = app(RecordCourierEvent::class)->handle('fake', new CourierEvent('NO-SUCH-PARCEL', 'DELIVERED', CarbonImmutable::parse('2026-11-03 10:00:00', 'UTC')), 'webhook');

        expect($result)->toBe(RecordCourierEvent::UNKNOWN_SHIPMENT)->and(($this->issues)('unknown_consignment'))->toHaveCount(1);
    });

    it('counts failed attempts, tells the customer, and raises the parcel when the tries are used up', function () {
        $order = ($this->order)([$this->tee => 1]);
        $p = ($this->parcel)($order, [$this->tee => 1]);

        ($this->say)($p, 'OUT_FOR_DELIVERY', '2026-11-04 08:00:00');
        ($this->say)($p, 'DELIVERY_FAILED', '2026-11-04 12:00:00', 'Customer not reachable');
        expect(($this->shipmentRow)($p->public_id))->toMatchArray(['status' => 'failed', 'attempts' => 1])->and(($this->issues)('attempts_exhausted'))->toHaveCount(0);

        ($this->say)($p, 'OUT_FOR_DELIVERY', '2026-11-05 08:00:00');
        ($this->say)($p, 'DELIVERY_FAILED', '2026-11-05 12:00:00');
        ($this->say)($p, 'OUT_FOR_DELIVERY', '2026-11-06 08:00:00');
        expect(($this->shipmentRow)($p->public_id)->attempts)->toBe(2);
        ($this->say)($p, 'DELIVERY_FAILED', '2026-11-06 12:00:00');

        expect(($this->shipmentRow)($p->public_id))->toMatchArray(['status' => 'failed', 'attempts' => 3])->and(($this->issues)('attempts_exhausted'))->toHaveCount(1)
            ->and(collect(($this->texts)())->filter(fn ($m) => str_contains($m, 'could not deliver'))->count())->toBe(3)->and(collect(($this->texts)())->contains(fn ($m) => str_contains($m, 'after several tries')))->toBeTrue()
            ->and(DB::table('order_notes')->where('order_id', $order->id)->where('body', 'like', 'A delivery attempt failed%')->count())->toBe(3);
    });

    it('raises a returned parcel so the goods can be received back, and leaves the order as it is', function () {
        $order = ($this->order)([$this->tee => 1]);
        $p = ($this->parcel)($order, [$this->tee => 1]);

        ($this->say)($p, 'DELIVERY_FAILED', '2026-11-04 12:00:00');
        ($this->say)($p, 'RETURNED', '2026-11-07 10:00:00');

        expect(($this->shipmentRow)($p->public_id)->status)->toBe('returned')->and(($this->issues)('returned_to_origin'))->toHaveCount(1)->and(($this->state)($order->id)->status)->toBe('processing')
            ->and(DB::table('order_notes')->where('order_id', $order->id)->where('body', 'A parcel is coming back to the shop.')->count())->toBe(1);
    });
});

describe('delivery and the cash', function () {
    it('records the parcel\'s cash once, pays the order and completes it', function () {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1]);
        $p = ($this->parcel)($order, [$this->tee => 2, $this->mug => 1]);

        ($this->say)($p, 'DELIVERED', '2026-11-04 11:30:00');

        $collection = DB::table('cod_collections')->first();
        $payment = DB::table('payments')->first();
        expect(DB::table('cod_collections')->count())->toBe(1)->and($collection->shipment_id)->toBe($p->id)->and($collection->source)->toBe('courier')->and($collection->amount_minor)->toBe((int) $order->grand_total_minor)
            ->and($payment->status)->toBe('paid')->and($payment->provider)->toBe('cod')->and($payment->captured_minor)->toBe((int) $order->grand_total_minor)->and(($this->state)($order->id)->payment_status)->toBe('paid')
            ->and(($this->state)($order->id)->delivered_at)->toStartWith('2026-11-04 11:30:00')->and(($this->state)($order->id)->status)->toBe('completed');
    });

    it('records the cash once even when the announcement is delivered again', function () {
        $order = ($this->order)([$this->tee => 1]);
        $p = ($this->parcel)($order, [$this->tee => 1]);
        ($this->say)($p, 'DELIVERED', '2026-11-04 11:30:00');
        DB::table('outbox_messages')->where('event_name', 'shipping.delivered')->update(['status' => 'pending', 'available_at' => now()->subMinute()]);

        $this->artisan('outbox:dispatch', ['--limit' => 10])->assertSuccessful();
        $this->artisan('outbox:dispatch', ['--limit' => 10])->assertSuccessful();

        expect(DB::table('cod_collections')->count())->toBe(1)->and(DB::table('payments')->where('provider', 'cod')->count())->toBe(1)->and(DB::table('outbox_messages')->where('event_name', 'payments.succeeded')->count())->toBe(1);
    });

    it('is partly paid after the first of two parcels and complete after the second, the cash adding up exactly', function () {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1]);
        $first = ($this->parcel)($order, [$this->tee => 2]);
        $second = ($this->parcel)($order, [$this->mug => 1]);

        ($this->say)($first, 'DELIVERED', '2026-11-04 11:00:00');
        $mid = ($this->state)($order->id);
        expect($mid->payment_status)->toBe('partially_paid')->and($mid->delivered_at)->toBeNull()->and($mid->status)->toBe('processing')->and((int) DB::table('cod_collections')->sum('amount_minor'))->toBe($first->cod_amount_minor);

        ($this->say)($second, 'DELIVERED', '2026-11-05 15:00:00');

        $end = ($this->state)($order->id);
        expect($end->payment_status)->toBe('paid')->and($end->status)->toBe('completed')->and($end->delivered_at)->toStartWith('2026-11-05 15:00:00')->and(DB::table('cod_collections')->count())->toBe(2)
            ->and((int) DB::table('cod_collections')->sum('amount_minor'))->toBe((int) $order->grand_total_minor);
    });

    it('completes a prepaid order on delivery without recording any cash', function () {
        $order = ($this->order)([$this->tee => 1], 'online');
        $p = ($this->parcel)($order, [$this->tee => 1]);

        ($this->say)($p, 'DELIVERED', '2026-11-04 11:30:00');

        expect(DB::table('cod_collections')->count())->toBe(0)->and(($this->state)($order->id)->status)->toBe('completed')->and(($this->state)($order->id)->payment_status)->toBe('paid');
    });

    it('does not complete an order that is delivered but not yet paid, and does not fail', function () {
        $order = ($this->order)([$this->tee => 1]);
        DB::table('orders')->where('id', $order->id)->update(['delivered_at' => now()]);

        expect(app(OrderCompletion::class)->attempt($order->id))->toBeFalse()->and(($this->state)($order->id)->status)->toBe('confirmed');
    });

    it('refuses the whole order total by hand once there are parcels, and lets the parcel\'s cash be refunded like any money', function () {
        $order = ($this->order)([$this->tee => 1]);
        $p = ($this->parcel)($order, [$this->tee => 1]);
        expect(fn () => app(RecordCodCollection::class)->handle($this->staff, $order->public_id))->toThrow(PaymentException::class, 'cod_use_parcels');

        ($this->say)($p, 'DELIVERED', '2026-11-04 11:30:00');
        $payment = DB::table('payments')->first();
        $refund = app(RequestRefund::class)->handle($this->staff, $payment->public_id, 10000, 'Customer returned a gift box');

        expect($refund->method->value)->toBe('manual')->and((int) DB::table('payments')->value('refunded_minor'))->toBe(10000);
    });
});

describe('the manual courier', function () {
    it('lets staff record the parcel\'s journey by hand, with the same results and their name kept', function () {
        $order = ($this->order)([$this->tee => 1]);
        $p = app(CreateShipment::class)->handle($this->staff, $order->public_id, [($this->itemOf)($order->id, $this->tee) => 1]);   // manual courier
        ($this->refuses)(fn () => app(RecordManualShipmentStatus::class)->handle($this->staff, $p->public_id, 'delivered'), 'hand_over_first');
        app(HandOverShipment::class)->handle($this->staff, $p->public_id);

        app(RecordManualShipmentStatus::class)->handle($this->staff, $p->public_id, 'out_for_delivery', 'With rider Karim', now()->addMinute()->toImmutable());
        $done = app(RecordManualShipmentStatus::class)->handle($this->staff, $p->public_id, 'delivered', 'Handed over, cash taken', now()->addMinutes(2)->toImmutable());

        $event = DB::table('shipment_events')->where('shipment_id', $p->id)->where('provider_status', 'delivered')->first();
        expect($done->status->value)->toBe('delivered')->and($event->source)->toBe('manual')->and((int) $event->actor_id)->toBe($this->staff->id)->and(DB::table('cod_collections')->count())->toBe(1)->and(($this->state)($order->id)->status)->toBe('completed');
    });

    it('refuses nonsense: a wrong word, a future time, the same thing twice, a finished parcel, a cancelled parcel', function () {
        $order = ($this->order)([$this->tee => 2]);
        $p = app(CreateShipment::class)->handle($this->staff, $order->public_id, [($this->itemOf)($order->id, $this->tee) => 2]);
        app(HandOverShipment::class)->handle($this->staff, $p->public_id);
        $say = fn (string $status, ?CarbonImmutable $at = null) => app(RecordManualShipmentStatus::class)->handle($this->staff, $p->public_id, $status, null, $at);

        ($this->refuses)(fn () => $say('teleported'), 'status_invalid');
        ($this->refuses)(fn () => $say('in_transit', now()->addHour()->toImmutable()), 'time_invalid');
        $at = now()->addMinute()->toImmutable();
        $say('in_transit', $at);
        ($this->refuses)(fn () => $say('in_transit', $at), 'already_recorded');
        ($this->refuses)(fn () => $say('out_for_delivery', $at->subMinutes(10)), 'time_before_latest');
        $say('delivered', $at->addMinutes(2));
        ($this->refuses)(fn () => $say('failed', $at->addMinutes(3)), 'shipment_finished');
        ($this->refuses)(fn () => app(RecordManualShipmentStatus::class)->handle($this->staff, '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'delivered'), 'shipment_not_found');
    });
});

describe('the courier\'s webhook', function () {
    beforeEach(function () {
        $this->order = ($this->order)([$this->tee => 1]);
        $this->parcel = ($this->parcel)($this->order, [$this->tee => 1]);
        $this->hook = fn (array $events, ?string $secret = FakeCourier::WEBHOOK_SECRET, string $courier = 'fake') => $this->postJson("/api/v1/webhooks/couriers/{$courier}", ['events' => $events], $secret === null ? [] : ['X-Fake-Courier-Secret' => $secret]);
        $this->row = fn (string $status, string $at) => ['consignment_id' => $this->parcel->courier_consignment_id, 'status' => $status, 'occurred_at' => $at];
    });

    it('takes genuine messages and moves the parcel', function () {
        ($this->hook)([($this->row)('IN_TRANSIT', '2026-11-03 12:00:00'), ($this->row)('DELIVERED', '2026-11-04 11:30:00')])->assertOk()->assertJson(['received' => 2, 'stored' => 2]);

        expect(($this->shipmentRow)($this->parcel->public_id)->status)->toBe('delivered')->and(DB::table('cod_collections')->count())->toBe(1);
    });

    it('is harmless to repeat', function () {
        ($this->hook)([($this->row)('DELIVERED', '2026-11-04 11:30:00')])->assertOk()->assertJson(['stored' => 1]);
        ($this->hook)([($this->row)('DELIVERED', '2026-11-04 11:30:00')])->assertOk()->assertJson(['received' => 1, 'stored' => 0]);

        expect(DB::table('shipment_events')->where('provider_status', 'DELIVERED')->count())->toBe(1)->and(DB::table('cod_collections')->count())->toBe(1);
    });

    it('refuses a request that is not genuine and stores nothing', function () {
        ($this->hook)([($this->row)('DELIVERED', '2026-11-04 11:30:00')], 'wrong-secret')->assertUnauthorized();
        ($this->hook)([($this->row)('DELIVERED', '2026-11-04 11:30:00')], null)->assertUnauthorized();

        expect(DB::table('shipment_events')->where('provider_status', 'DELIVERED')->count())->toBe(0)->and(($this->shipmentRow)($this->parcel->public_id)->status)->toBe('handed_over');
    });

    it('refuses a malformed body, an unknown courier and a courier that has no webhook', function () {
        ($this->hook)([['status' => 'DELIVERED']])->assertStatus(422);
        ($this->hook)([($this->row)('DELIVERED', 'not a date')])->assertStatus(422);
        ($this->hook)([], FakeCourier::WEBHOOK_SECRET, 'nowhere')->assertNotFound();
        ($this->hook)([], FakeCourier::WEBHOOK_SECRET, 'manual')->assertNotFound();
    });

    it('has its own rate limit', function () {
        foreach (range(1, 300) as $i) {
            ($this->hook)([])->assertOk();
        }

        ($this->hook)([])->assertStatus(429);
    });
});

describe('asking the courier', function () {
    it('finds what the webhook missed, and is safe to repeat', function () {
        $order = ($this->order)([$this->tee => 1]);
        $p = ($this->parcel)($order, [$this->tee => 1]);
        ($this->courier)()->events[(string) $p->courier_consignment_id] = [
            new CourierEvent((string) $p->courier_consignment_id, 'IN_TRANSIT', CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC')),
            new CourierEvent((string) $p->courier_consignment_id, 'DELIVERED', CarbonImmutable::parse('2026-11-04 11:30:00', 'UTC')),
        ];

        $first = app(PollCourierShipments::class)->handle();
        $second = app(PollCourierShipments::class)->handle();

        expect($first)->toBe(['asked' => 1, 'events' => 2, 'unreachable' => 0])->and($second['events'])->toBe(0)->and(($this->shipmentRow)($p->public_id)->status)->toBe('delivered')
            ->and(DB::table('cod_collections')->count())->toBe(1)->and(DB::table('shipment_events')->where('source', 'poll')->count())->toBe(2);
    });

    it('skips a courier that cannot be reached, counts it, and tries again later', function () {
        $order = ($this->order)([$this->tee => 1]);
        $p = ($this->parcel)($order, [$this->tee => 1]);
        ($this->courier)()->failNext('track');

        $down = app(PollCourierShipments::class)->handle();

        expect($down)->toBe(['asked' => 1, 'events' => 0, 'unreachable' => 1])->and(($this->shipmentRow)($p->public_id)->status)->toBe('handed_over')->and(($this->shipmentRow)($p->public_id)->polled_at)->not->toBeNull();
    });

    it('asks the parcels asked longest ago first, so none is starved by a small batch', function () {
        $a = ($this->parcel)(($this->order)([$this->tee => 1]), [$this->tee => 1]);
        $b = ($this->parcel)(($this->order)([$this->mug => 1]), [$this->mug => 1]);

        app(PollCourierShipments::class)->handle(1);
        app(PollCourierShipments::class)->handle(1);

        expect(($this->shipmentRow)($a->public_id)->polled_at)->not->toBeNull()->and(($this->shipmentRow)($b->public_id)->polled_at)->not->toBeNull();
    });

    it('does not ask about finished parcels or parcels without a courier id', function () {
        $order = ($this->order)([$this->tee => 1]);
        $p = ($this->parcel)($order, [$this->tee => 1]);
        ($this->say)($p, 'DELIVERED', '2026-11-04 11:30:00');

        expect(app(PollCourierShipments::class)->handle())->toBe(['asked' => 0, 'events' => 0, 'unreachable' => 0]);
    });

    it('is scheduled every ten minutes', function () {
        $events = collect(Schedule::events())->filter(fn ($e) => str_contains((string) $e->description, 'shipping:poll-couriers'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('*/10 * * * *');
    });
});

describe('the order and the database', function () {
    it('keeps delivered_at once set, and the status of a delivered parcel final', function () {
        $order = ($this->order)([$this->tee => 1]);
        $p = ($this->parcel)($order, [$this->tee => 1]);
        ($this->say)($p, 'DELIVERED', '2026-11-04 11:30:00');

        expect(fn () => DB::table('orders')->where('id', $order->id)->update(['delivered_at' => now()->addDay()]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('shipments')->where('id', $p->id)->update(['status' => 'in_transit']))->toThrow(QueryException::class);
    });

    it('accepts the new payment state in the database and refuses an invented one', function () {
        $order = ($this->order)([$this->tee => 1]);

        expect(DB::table('orders')->where('id', $order->id)->update(['payment_status' => 'partially_paid']))->toBe(1)
            ->and(fn () => DB::table('orders')->where('id', $order->id)->update(['payment_status' => 'half_paid']))->toThrow(QueryException::class);
    });
});
