<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\CancelBooking;
use App\Modules\Bookings\Actions\EndBookingsForRefundedOrder;
use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Actions\MarkNoShow;
use App\Modules\Bookings\Actions\ReassignBooking;
use App\Modules\Bookings\Actions\RescheduleBooking;
use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SaveServiceResource;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Bookings\Contracts\CancellationPolicyTexts;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Services\CancellationPolicies;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\PlaceOrderCommand;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Services\CheckoutPricing;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Outbox\OutboxDispatcher;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    bookingsWorld($this);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->seed([PriceListSeeder::class, PaymentMethodSeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    app(SetRegularPrice::class)->handle($this->staff, $this->variant30, Money::of(50000));   // ৳500.00
    app(TransitionProduct::class)->publish($this->staff, Product::query()->whereHas('variants', fn ($q) => $q->whereKey($this->variant30))->firstOrFail());
    $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));   // exactly 24 hours before a 10:00 Dhaka start on Tuesday 1 December
    app(SaveServiceDefinition::class)->handle($this->staff, 'SVC-30', 'at_provider', 60, 60, 15, true);
    foreach ([$this->tailor, $this->room] as $resource) {
        app(SetWeeklyAvailability::class)->handle($this->staff, $resource, [['weekday' => 2, 'start' => '09:00', 'end' => '12:00']]);
    }
    $this->owner = User::factory()->create(['email' => 'o@example.com']);
    $this->other = User::factory()->create(['email' => 'x@example.com']);
    $this->customer = fn (User $u) => new Actor(ActorType::Customer, $u->id);
    $this->local = fn (string $t) => CarbonImmutable::parse("2026-12-01 {$t}", 'Asia/Dhaka')->utc()->format('Y-m-d H:i:s');

    /** A paid, confirmed booking for $user at the given local time. Returns its public id. */
    $this->booked = function (?User $user = null, string $local = '10:00', ?string $resource = null) {
        $user ??= $this->owner;
        $owner = CartOwner::user($user->id);
        app(HoldSlot::class)->handle($owner, 'SVC-30', $resource ?? $this->tailor->public_id, ($this->local)($local));
        $pay = 'online';
        $order = app(PlaceOrder::class)->handle(new PlaceOrderCommand(
            actor: Actor::guest('checkout'), owner: $owner, userId: $user->id, contactName: 'Rahim Uddin', contactPhone: '01712345678', contactEmail: null, address: null, shippingMethodCode: null, paymentMethodCode: $pay,
            expectedTotalMinor: app(CheckoutPricing::class)->quote(app(CartReader::class)->view($owner, 'en'), null, null, $pay, 'en')->breakdown->grandTotalMinor, placeToken: 'token-'.Str::random(12), locale: 'en',
        ));
        app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Confirmed, 'test');
        app(TransitionOrder::class)->payment(Actor::system('t'), $order->id, PaymentStatus::Paid, 'test');
        app(OutboxDispatcher::class)->dispatchDue();
        $this->lastOrder = $order;

        return (string) DB::table('service_bookings')->where('order_item_id', DB::table('order_items')->where('order_id', $order->id)->value('id'))->value('public_id');
    };
    $this->row = fn (string $publicId) => DB::table('service_bookings')->where('public_id', $publicId)->first();
    $this->events = fn (string $publicId) => DB::table('booking_events')->where('booking_id', ($this->row)($publicId)->id)->orderBy('id')->pluck('event')->all();
    $this->ended = fn (string $publicId) => DB::table('booking_cancellations')->where('booking_id', ($this->row)($publicId)->id)->first();
    $this->staffCancel = fn (string $id, bool $waive = false, ?string $reason = 'customer called') => app(CancelBooking::class)->handle($this->staff, $id, $reason, $waive);
    $this->refuses = fn (callable $call, string $reason) => expect($call)->toThrow(BookingException::class, $reason);
});

describe('the fee a policy asks for', function () {
    $terms = fn (array $over = []) => $over + ['code' => 't', 'windows' => [['hours' => 48, 'fee_bp' => 0], ['hours' => 24, 'fee_bp' => 2500]], 'late_fee_bp' => 7500, 'no_show_fee_bp' => 10000, 'reschedule_hours' => 24, 'max_reschedules' => 2];

    it('picks the most generous window the customer still qualifies for, else the late fee, with every edge exact', function () use ($terms) {
        $p = new CancellationPolicies;

        expect($p->cancelFeeBp($terms(), 48 * 60))->toBe(0)->and($p->cancelFeeBp($terms(), 48 * 60 - 1))->toBe(2500)->and($p->cancelFeeBp($terms(), 24 * 60))->toBe(2500)
            ->and($p->cancelFeeBp($terms(), 24 * 60 - 1))->toBe(7500)->and($p->cancelFeeBp($terms(), 0))->toBe(7500)->and($p->cancelFeeBp($terms(), -30))->toBe(7500)
            ->and($p->cancelFeeBp($terms(['windows' => []]), 100000))->toBe(7500);
    });

    it('rounds the fee down to a whole minor unit, so a refund is never more than was paid', function () {
        $p = new CancellationPolicies;

        expect($p->feeOn(99999, 5000))->toBe(49999)->and($p->feeOn(1, 5000))->toBe(0)->and($p->feeOn(50000, 5000))->toBe(25000)->and($p->feeOn(50000, 10000))->toBe(50000)->and($p->feeOn(50000, 0))->toBe(0)
            ->and($p->feeOn(33333, 3333))->toBe(11109);
        foreach ([1, 7, 99999, 123457] as $paid) {
            foreach ([0, 1, 3333, 5000, 9999, 10000] as $bp) {
                expect($p->feeOn($paid, $bp))->toBeLessThanOrEqual($paid);
            }
        }
    });
});

describe('cancelling', function () {
    it('is free until 24 hours before, gives the time back and records what is due, once', function () {
        $id = ($this->booked)();
        $result = app(CancelBooking::class)->handle(($this->customer)($this->owner), $id, 'plans changed');
        app(OutboxDispatcher::class)->dispatchDue();

        $row = ($this->ended)($id);
        expect(($this->row)($id)->status)->toBe('cancelled')->and($result->fee_minor)->toBe(0)->and($result->refund_due_minor)->toBe(50000)
            ->and($row->kind)->toBe('cancelled')->and($row->by_type)->toBe('customer')->and((int) $row->by_id)->toBe($this->owner->id)->and((int) $row->minutes_before)->toBe(1440)
            ->and((int) $row->paid_minor)->toBe(50000)->and((int) $row->fee_minor)->toBe(0)->and((int) $row->refund_due_minor)->toBe(50000)->and($row->reason)->toBe('plans changed')
            ->and(array_slice(($this->events)($id), -1))->toBe(['cancelled'])->and(DB::table('outbox_messages')->where('event_name', 'bookings.ended')->count())->toBe(1);
        $bob = CartOwner::guest(str_repeat('b', 40));
        expect(app(HoldSlot::class)->handle($bob, 'SVC-30', $this->tailor->public_id, ($this->local)('10:00'))->status->value)->toBe('held');   // the time is free again
        ($this->refuses)(fn () => app(CancelBooking::class)->handle(($this->customer)($this->owner), $id), 'not_cancellable');
        expect(DB::table('booking_cancellations')->count())->toBe(1);
    });

    it('asks half the price once it is closer than 24 hours, and the whole price at the start', function () {
        $first = ($this->booked)();
        $second = ($this->booked)(null, '11:00');
        $this->travel(1)->minutes();   // 23 h 59 min before the first

        $a = app(CancelBooking::class)->handle(($this->customer)($this->owner), $first);
        expect($a->fee_bp)->toBe(5000)->and($a->fee_minor)->toBe(25000)->and($a->refund_due_minor)->toBe(25000);

        $this->travelTo(CarbonImmutable::parse('2026-12-01 05:30:00', 'UTC'));   // the second starts at 05:00 UTC: already started for a customer
        ($this->refuses)(fn () => app(CancelBooking::class)->handle(($this->customer)($this->owner), $second), 'already_started');
    });

    it('lets staff cancel with a reason, waive the fee with a reason, and never a customer waive it', function () {
        $id = ($this->booked)();
        $this->travel(1)->minutes();

        ($this->refuses)(fn () => app(CancelBooking::class)->handle(($this->customer)($this->owner), $id, null, true), 'waive_staff_only');
        ($this->refuses)(fn () => app(CancelBooking::class)->handle($this->staff, $id, '  '), 'reason_required');
        expect(($this->row)($id)->status)->toBe('confirmed');

        $r = ($this->staffCancel)($id, true, 'our fault');
        $row = ($this->ended)($id);
        expect($r->fee_minor)->toBe(0)->and($r->refund_due_minor)->toBe(50000)->and((bool) $row->fee_waived)->toBeTrue()->and($row->by_type)->toBe('staff')->and((int) $row->fee_bp)->toBe(5000);
    });

    it('treats another customer, a guest, a hold nobody ordered and an unknown booking alike: not found, nothing changed', function () {
        $id = ($this->booked)();

        ($this->refuses)(fn () => app(CancelBooking::class)->handle(($this->customer)($this->other), $id), 'not_found');
        ($this->refuses)(fn () => app(CancelBooking::class)->handle(Actor::guest('x'), $id, 'x'), 'not_found');
        ($this->refuses)(fn () => app(CancelBooking::class)->handle(($this->customer)($this->owner), '01zzzzzzzzzzzzzzzzzzzzzzzz'), 'not_found');
        $bob = CartOwner::user($this->other->id);
        $hold = app(HoldSlot::class)->handle($bob, 'SVC-30', $this->tailor->public_id, ($this->local)('11:00'));
        ($this->refuses)(fn () => app(CancelBooking::class)->handle(($this->customer)($this->other), $hold->public_id), 'not_found');
        expect(($this->row)($id)->status)->toBe('confirmed')->and(DB::table('booking_cancellations')->count())->toBe(0);
    });

    it('is held to the terms the booking was confirmed under, whatever the policy says later', function () {
        $id = ($this->booked)();
        DB::table('cancellation_policies')->update(['late_fee_bp' => 10000]);
        DB::table('cancellation_policy_windows')->update(['hours_before' => 100]);
        $this->travel(1)->minutes();

        expect(app(CancelBooking::class)->handle(($this->customer)($this->owner), $id)->fee_bp)->toBe(5000);
    });

    it('can never be changed or removed afterwards', function () {
        $id = ($this->booked)();
        ($this->staffCancel)($id);

        expect(fn () => DB::table('booking_cancellations')->update(['fee_minor' => 0]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('booking_cancellations')->delete())->toThrow(QueryException::class);
    });

    it('is refused by the database when the money does not add up', function () {
        $id = ($this->booked)();
        $bookingId = ($this->row)($id)->id;
        $row = ['booking_id' => $bookingId, 'kind' => 'cancelled', 'by_type' => 'staff', 'minutes_before' => 10, 'paid_minor' => 1000, 'fee_bp' => 5000, 'fee_waived' => false, 'fee_minor' => 500, 'refund_due_minor' => 500, 'created_at' => now()];

        foreach ([['fee_minor' => 1001], ['refund_due_minor' => 501], ['fee_bp' => 10001], ['kind' => 'other'], ['fee_waived' => true]] as $bad) {
            expect(fn () => DB::table('booking_cancellations')->insert($bad + $row))->toThrow(QueryException::class);
        }
        expect(DB::table('booking_cancellations')->count())->toBe(0);
    });
});

describe('a customer who does not turn up', function () {
    it('is recorded by staff only once the time has come, with the no-show fee, and a customer cannot do it', function () {
        $id = ($this->booked)();

        ($this->refuses)(fn () => app(MarkNoShow::class)->handle($this->staff, $id), 'no_show_too_early');
        ($this->refuses)(fn () => app(MarkNoShow::class)->handle(($this->customer)($this->owner), $id), 'not_found');
        $this->travelTo(CarbonImmutable::parse('2026-12-01 04:10:00', 'UTC'));
        $r = app(MarkNoShow::class)->handle($this->staff, $id, 'did not come');

        $row = ($this->ended)($id);
        expect(($this->row)($id)->status)->toBe('no_show')->and($r->fee_minor)->toBe(50000)->and($r->refund_due_minor)->toBe(0)->and($row->kind)->toBe('no_show')->and((int) $row->minutes_before)->toBe(-10)
            ->and(array_slice(($this->events)($id), -1))->toBe(['no_show']);
        ($this->refuses)(fn () => app(MarkNoShow::class)->handle($this->staff, $id), 'not_cancellable');
    });

    it('may have the fee waived', function () {
        $id = ($this->booked)();
        $this->travelTo(CarbonImmutable::parse('2026-12-01 04:10:00', 'UTC'));

        $r = app(MarkNoShow::class)->handle($this->staff, $id, 'was ill', true);

        expect($r->fee_minor)->toBe(0)->and($r->refund_due_minor)->toBe(50000);
    });
});

describe('an order refunded in full', function () {
    it('gives back the time of upcoming bookings, with no fee and nothing more due, and leaves started or partly refunded ones', function () {
        $partly = ($this->booked)(null, '09:00');
        $partlyOrder = $this->lastOrder;
        $id = ($this->booked)();
        $order = $this->lastOrder;

        app(TransitionOrder::class)->payment(Actor::system('t'), $partlyOrder->id, PaymentStatus::PartiallyRefunded, 'part');
        app(TransitionOrder::class)->payment(Actor::system('t'), $order->id, PaymentStatus::Refunded, 'all');
        app(OutboxDispatcher::class)->dispatchDue();

        $row = ($this->ended)($id);
        expect(($this->row)($id)->status)->toBe('cancelled')->and($row->by_type)->toBe('system')->and((int) $row->fee_minor)->toBe(0)->and((int) $row->refund_due_minor)->toBe(0)->and((bool) $row->fee_waived)->toBeTrue()
            ->and(($this->row)($partly)->status)->toBe('confirmed');
    });
});

describe('an order refunded in full, once the booking has started', function () {
    it('leaves a started booking to staff', function () {
        $id = ($this->booked)();
        $order = $this->lastOrder;
        $this->travelTo(CarbonImmutable::parse('2026-12-01 04:10:00', 'UTC'));

        expect(app(EndBookingsForRefundedOrder::class)->handle($order->id))->toBe(0)->and(($this->row)($id)->status)->toBe('confirmed')->and(DB::table('booking_cancellations')->count())->toBe(0);
    });
});

describe('moving a booking', function () {
    it('keeps the order line and the terms, counts the move, and may overlap its own old time', function () {
        $id = ($this->booked)();
        $item = ($this->row)($id)->order_item_id;
        $terms = ($this->row)($id)->policy_terms;

        app(RescheduleBooking::class)->handle(($this->customer)($this->owner), $id, ($this->local)('10:15'));   // overlaps where it was
        app(OutboxDispatcher::class)->dispatchDue();

        $r = ($this->row)($id);
        expect($r->status)->toBe('confirmed')->and(CarbonImmutable::parse($r->starts_at, 'UTC')->format('H:i'))->toBe('04:15')->and(CarbonImmutable::parse($r->ends_at, 'UTC')->format('H:i'))->toBe('04:45')
            ->and(CarbonImmutable::parse($r->blocks_until, 'UTC')->format('H:i'))->toBe('04:55')->and((int) $r->reschedule_count)->toBe(1)->and($r->order_item_id)->toBe($item)->and($r->policy_terms)->toBe($terms)
            ->and(array_slice(($this->events)($id), -1))->toBe(['rescheduled'])->and(DB::table('outbox_messages')->where('event_name', 'bookings.rescheduled')->count())->toBe(1);
    });

    it('is refused onto a time somebody else has, onto the time it already has, or a made-up time', function () {
        $mine = ($this->booked)();
        $theirs = ($this->booked)(User::factory()->create(['email' => 'b@example.com']), '11:00');

        ($this->refuses)(fn () => app(RescheduleBooking::class)->handle(($this->customer)($this->owner), $mine, ($this->local)('11:00')), 'slot_unavailable');
        ($this->refuses)(fn () => app(RescheduleBooking::class)->handle(($this->customer)($this->owner), $mine, ($this->local)('10:00')), 'same_time');
        ($this->refuses)(fn () => app(RescheduleBooking::class)->handle(($this->customer)($this->owner), $mine, ($this->local)('10:07')), 'slot_unavailable');
        ($this->refuses)(fn () => app(RescheduleBooking::class)->handle(($this->customer)($this->owner), $mine, 'not a time'), 'time_invalid');
        expect(CarbonImmutable::parse(($this->row)($mine)->starts_at, 'UTC')->format('H:i'))->toBe('04:00')->and((int) ($this->row)($mine)->reschedule_count)->toBe(0)->and(($this->row)($theirs)->status)->toBe('confirmed');
    });

    it('is limited by the terms: how late, how often, only your own, only before it starts; staff may override with a reason', function () {
        $id = ($this->booked)();
        $me = ($this->customer)($this->owner);

        ($this->refuses)(fn () => app(RescheduleBooking::class)->handle(($this->customer)($this->other), $id, ($this->local)('10:30')), 'not_found');
        app(RescheduleBooking::class)->handle($me, $id, ($this->local)('10:30'));   // 24 h before the original start: the move is judged on the time it has now, 10:30, so it is allowed
        DB::table('service_bookings')->where('public_id', $id)->update(['reschedule_count' => 2]);
        ($this->refuses)(fn () => app(RescheduleBooking::class)->handle($me, $id, ($this->local)('11:00')), 'reschedule_limit');
        ($this->refuses)(fn () => app(RescheduleBooking::class)->handle($me, $id, ($this->local)('11:00'), true), 'reschedule_limit');   // a customer cannot override
        ($this->refuses)(fn () => app(RescheduleBooking::class)->handle($this->staff, $id, ($this->local)('11:00'), true), 'reason_required');
        app(RescheduleBooking::class)->handle($this->staff, $id, ($this->local)('11:00'), true, 'customer phoned');
        expect((int) ($this->row)($id)->reschedule_count)->toBe(3);

        $this->travel(1)->hours();   // 23 hours before the booking's new start of 05:00 UTC
        $late = ($this->booked)(null, '09:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-30 04:30:00', 'UTC'));
        ($this->refuses)(fn () => app(RescheduleBooking::class)->handle($me, $late, ($this->local)('09:15')), 'reschedule_too_late');
        $this->travelTo(CarbonImmutable::parse('2026-12-01 03:30:00', 'UTC'));
        ($this->refuses)(fn () => app(RescheduleBooking::class)->handle($me, $late, ($this->local)('09:15')), 'already_started');
    });

    it('is only for a confirmed booking', function () {
        $id = ($this->booked)();
        ($this->staffCancel)($id);

        ($this->refuses)(fn () => app(RescheduleBooking::class)->handle($this->staff, $id, ($this->local)('10:30'), true, 'x'), 'not_reschedulable');
    });
});

describe('giving a booking to another person or room', function () {
    it('moves it to a free resource for the same time, and refuses a busy one, a stranger, and a customer', function () {
        $id = ($this->booked)();
        ($this->booked)(User::factory()->create(['email' => 'b@example.com']), '10:00', $this->room->public_id);
        $elsewhere = DB::table('service_resources')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'name' => 'Van', 'type' => 'vehicle', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $otherService = app(SaveServiceResource::class)->handle($this->staff, null, 'Online coach', 'staff', true, ['SVC-60']);   // free then, but does another service
        app(SetWeeklyAvailability::class)->handle($this->staff, $otherService, [['weekday' => 2, 'start' => '09:00', 'end' => '12:00']]);
        ($this->refuses)(fn () => app(ReassignBooking::class)->handle($this->staff, $id, $otherService->public_id), 'resource_invalid');
        ($this->refuses)(fn () => app(ReassignBooking::class)->handle($this->staff, $id, $this->room->public_id), 'slot_unavailable');   // the room is busy then
        ($this->refuses)(fn () => app(ReassignBooking::class)->handle($this->staff, $id, (string) DB::table('service_resources')->where('id', $elsewhere)->value('public_id')), 'resource_invalid');
        ($this->refuses)(fn () => app(ReassignBooking::class)->handle(($this->customer)($this->owner), $id, $this->room->public_id), 'not_found');
        ($this->refuses)(fn () => app(ReassignBooking::class)->handle($this->staff, $id, $this->tailor->public_id), 'same_time');
        expect((int) ($this->row)($id)->resource_id)->toBe($this->tailor->id);

        $other = ($this->booked)(User::factory()->create(['email' => 'c@example.com']), '11:00');
        app(ReassignBooking::class)->handle($this->staff, $other, $this->room->public_id);
        expect((int) ($this->row)($other)->resource_id)->toBe($this->room->id)->and(array_slice(($this->events)($other), -1))->toBe(['reassigned']);
    });
});

describe('the words shown before booking', function () {
    it('come from the policy in the customer\'s language, fall back to English, and are shown once', function () {
        $texts = app(CancellationPolicyTexts::class);

        expect($texts->forSkus(['SVC-30', 'SVC-60'], 'en'))->toHaveCount(1)->and($texts->forSkus(['SVC-30'], 'en')[0]['title'])->toBe('Cancelling or moving a booking')
            ->and($texts->forSkus(['SVC-30'], 'bn')[0]['title'])->toBe('বুকিং বাতিল বা সরানো')->and($texts->forSkus(['SVC-30'], 'fr')[0]['title'])->toBe('Cancelling or moving a booking')
            ->and($texts->forSkus(['TEE-1'], 'en'))->toBe([])->and($texts->forSkus([], 'en'))->toBe([]);
    });

    it('are on the product page of a service and not of anything else', function () {
        $slug = (string) DB::table('product_translations as t')->join('product_variants as v', 'v.product_id', '=', 't.product_id')->where('v.id', $this->variant30)->where('t.locale', 'en')->value('t.slug');
        $bn = (string) DB::table('product_translations as t')->join('product_variants as v', 'v.product_id', '=', 't.product_id')->where('v.id', $this->variant30)->where('t.locale', 'bn')->value('t.slug');

        $this->get('/en/p/'.$slug)->assertOk()->assertSee('Cancelling or moving a booking')->assertSee('free up to 24 hours');
        $this->get('/bn/p/'.($bn ?: $slug))->assertOk()->assertSee('বুকিং বাতিল বা সরানো');
    });

    it('uses a service\'s own policy when it has one', function () {
        $id = DB::table('cancellation_policies')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'code' => 'strict', 'late_fee_bp' => 10000, 'no_show_fee_bp' => 10000, 'reschedule_hours' => 48, 'max_reschedules' => 1, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cancellation_policy_translations')->insert(['policy_id' => $id, 'locale' => 'en', 'title' => 'Strict rules', 'text' => 'No refunds.']);
        DB::table('service_definitions')->where('id', DB::table('service_packages')->where('variant_id', $this->variant30)->value('service_id'))->update(['cancellation_policy_id' => $id]);

        expect(app(CancellationPolicyTexts::class)->forSkus(['SVC-30'], 'bn')[0]['title'])->toBe('Strict rules')   // no Bangla yet: English is shown
            ->and(app(CancellationPolicies::class)->termsForService((int) DB::table('service_packages')->where('variant_id', $this->variant30)->value('service_id'))['code'])->toBe('strict');
    });

    it('is refused by the database for a second default policy or a fee above the whole price', function () {
        $row = ['public_id' => strtolower((string) Str::ulid()), 'code' => 'other', 'late_fee_bp' => 100, 'no_show_fee_bp' => 100, 'reschedule_hours' => 1, 'max_reschedules' => 1, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()];
        expect(fn () => DB::table('cancellation_policies')->insert($row))->toThrow(QueryException::class);
        expect(fn () => DB::table('cancellation_policies')->insert(['is_default' => false, 'late_fee_bp' => 10001] + $row))->toThrow(QueryException::class);
    });
});
