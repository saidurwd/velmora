<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
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
use App\Modules\Platform\Outbox\OutboxDispatcher;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    bookingsWorld($this);
    $this->seed([RolesAndPermissionsSeeder::class, PriceListSeeder::class, PaymentMethodSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    config(['services.sms.driver' => 'fake']);
    Mail::fake();
    app(SetRegularPrice::class)->handle($this->staff, $this->variant30, Money::of(50000));
    app(TransitionProduct::class)->publish($this->staff, Product::query()->whereHas('variants', fn ($q) => $q->whereKey($this->variant30))->firstOrFail());
    $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));   // exactly 24 hours before a 10:00 Dhaka start on Tuesday 1 December
    app(SaveServiceDefinition::class)->handle($this->staff, 'SVC-30', 'at_provider', 60, 60, 15, true);
    foreach ([$this->tailor, $this->room] as $resource) {
        app(SetWeeklyAvailability::class)->handle($this->staff, $resource, [['weekday' => 2, 'start' => '09:00', 'end' => '12:00']]);
    }
    $this->customer = User::factory()->create(['email' => 'o@example.com']);
    $this->manager = catalogStaff(['bookings.view', 'bookings.manage']);
    $this->viewer = catalogStaff(['bookings.view']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->local = fn (string $t) => CarbonImmutable::parse("2026-12-01 {$t}", 'Asia/Dhaka')->utc()->format('Y-m-d H:i:s');

    $this->booked = function (string $local = '10:00', ?string $resource = null) {
        $owner = CartOwner::user($this->customer->id);
        app(HoldSlot::class)->handle($owner, 'SVC-30', $resource ?? $this->tailor->public_id, ($this->local)($local));
        $pay = 'online';
        $order = app(PlaceOrder::class)->handle(new PlaceOrderCommand(
            actor: Actor::guest('checkout'), owner: $owner, userId: $this->customer->id, contactName: 'Rahim Uddin', contactPhone: '01712345678', contactEmail: null, address: null, shippingMethodCode: null, paymentMethodCode: $pay,
            expectedTotalMinor: app(CheckoutPricing::class)->quote(app(CartReader::class)->view($owner, 'en'), null, null, $pay, 'en')->breakdown->grandTotalMinor, placeToken: 'token-'.Str::random(12), locale: 'en',
        ));
        app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Confirmed, 'test');
        app(TransitionOrder::class)->payment(Actor::system('t'), $order->id, PaymentStatus::Paid, 'test');
        app(OutboxDispatcher::class)->dispatchDue();
        $this->lastOrder = $order;

        return (string) DB::table('service_bookings')->where('order_item_id', DB::table('order_items')->where('order_id', $order->id)->value('id'))->value('public_id');
    };
    $this->row = fn (string $publicId) => DB::table('service_bookings')->where('public_id', $publicId)->first();
    $this->as = fn (User $u) => $this->actingAs($u, 'admin');
});

describe('who may see them', function () {
    it('needs a staff sign-in and the right permission, and only a manager can change anything', function () {
        $id = ($this->booked)();

        $this->get('/admin/bookings')->assertRedirect();
        ($this->as)($this->nobody)->get('/admin/bookings')->assertForbidden();
        ($this->as)($this->nobody)->get('/admin/bookings/calendar')->assertForbidden();
        ($this->as)($this->nobody)->get('/admin/bookings/'.$id)->assertForbidden();
        ($this->as)($this->viewer)->get('/admin/bookings')->assertOk();
        ($this->as)($this->viewer)->get('/admin/bookings/'.$id)->assertOk()->assertDontSee('Cancel booking');
        foreach (['cancel', 'no-show', 'complete', 'move', 'reassign'] as $action) {
            ($this->as)($this->viewer)->post("/admin/bookings/{$id}/{$action}", ['reason' => 'x'])->assertForbidden();
        }
        expect(($this->row)($id)->status)->toBe('confirmed');
    });
});

describe('the list', function () {
    it('shows bookings with the order and a masked phone, newest first, and filters them', function () {
        $a = ($this->booked)('10:00');
        $orderA = $this->lastOrder;
        $b = ($this->booked)('11:00', $this->room->public_id);
        DB::table('service_bookings')->where('public_id', $b)->update(['status' => 'completed']);

        $page = ($this->as)($this->viewer)->get('/admin/bookings')->assertOk()->assertSee('SVC-30')->assertSee($orderA->order_number)->assertSee('1 Dec 2026, 10:00 AM')->assertSee('500.00');
        expect($page->getContent())->not->toContain('01712345678');
        ($this->as)($this->viewer)->get('/admin/bookings?status=completed')->assertOk()->assertSee('/admin/bookings/'.$b, false)->assertDontSee('/admin/bookings/'.$a, false);
        ($this->as)($this->viewer)->get('/admin/bookings?order='.$orderA->order_number)->assertOk()->assertSee('/admin/bookings/'.$a, false)->assertDontSee('/admin/bookings/'.$b, false);
        ($this->as)($this->viewer)->get('/admin/bookings?resource='.$this->room->public_id)->assertOk()->assertSee('/admin/bookings/'.$b, false)->assertDontSee('/admin/bookings/'.$a, false);
        ($this->as)($this->viewer)->get('/admin/bookings?from=2026-12-02')->assertOk()->assertSee('No bookings match.');
        ($this->as)($this->viewer)->get('/admin/bookings?from=2026-12-01&to=2026-12-01&sku=SVC-30')->assertOk()->assertSee('/admin/bookings/'.$a, false);
        ($this->as)($this->viewer)->get('/admin/bookings?sku=NOPE')->assertOk()->assertSee('No bookings match.');
        ($this->as)($this->viewer)->get('/admin/bookings?from=garbage&status=nonsense')->assertOk();
    });

    it('does not ask more questions for more bookings, and reads in Bangla', function () {
        ($this->booked)('09:00');
        ($this->as)($this->viewer)->get('/admin/bookings')->assertOk();   // warm the caches shared by every page
        DB::enableQueryLog();
        ($this->as)($this->viewer)->get('/admin/bookings')->assertOk();
        $few = count(DB::getQueryLog());
        ($this->booked)('10:00');
        ($this->booked)('11:00', $this->room->public_id);
        DB::flushQueryLog();
        ($this->as)($this->viewer)->get('/admin/bookings')->assertOk();

        expect(count(DB::getQueryLog()))->toBe($few);
        $this->viewer->forceFill(['locale' => 'bn'])->save();
        ($this->as)($this->viewer)->get('/admin/bookings')->assertOk()->assertSee('নিশ্চিত');
    });
});

describe('one booking', function () {
    it('shows its story: terms, what each choice would cost, history, messages, and a way to the order', function () {
        $id = ($this->booked)();
        $this->travel(1)->minutes();

        ($this->as)($this->manager)->get('/admin/bookings/'.$id)->assertOk()->assertSee('Cancelling at least 24 hours before: 0% of the price is kept.')->assertSee('Cancelling closer than that: 50% is kept.')
            ->assertSee('250.00 is kept (50%)')->assertSee('/admin/orders/'.$this->lastOrder->public_id, false)->assertSee('confirmed')->assertSee('Cancel booking')->assertSee('Give to someone else');
        ($this->as)($this->manager)->get('/admin/bookings/01zzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
        expect(($this->row)($id)->status)->toBe('confirmed');
    });

    it('is not shown for a hold nobody ordered', function () {
        $hold = app(HoldSlot::class)->handle(CartOwner::guest(str_repeat('b', 40)), 'SVC-30', $this->tailor->public_id, ($this->local)('11:00'));

        ($this->as)($this->manager)->get('/admin/bookings/'.$hold->public_id)->assertNotFound();
    });

    it('cancels with a reason, with or without the fee, shows the money outcome and the way to approve the refund', function () {
        $id = ($this->booked)();
        $this->travel(1)->minutes();

        ($this->as)($this->manager)->post("/admin/bookings/{$id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        expect(($this->row)($id)->status)->toBe('confirmed');
        ($this->as)($this->manager)->post("/admin/bookings/{$id}/cancel", ['reason' => 'our tailor is ill', 'waive' => '1'])->assertRedirect('/admin/bookings/'.$id)->assertSessionHas('status', 'The booking was cancelled.');

        $row = DB::table('booking_cancellations')->first();
        expect(($this->row)($id)->status)->toBe('cancelled')->and($row->by_type)->toBe('staff')->and((bool) $row->fee_waived)->toBeTrue()->and((int) $row->refund_due_minor)->toBe(50000);
        ($this->as)($this->manager)->get('/admin/bookings/'.$id)->assertSee('Refund due: ৳')->assertSee('fee waived')->assertSee('Nothing is refunded automatically')->assertDontSee('Cancel booking');
        ($this->as)($this->manager)->post("/admin/bookings/{$id}/cancel", ['reason' => 'again'])->assertSessionHasErrors('booking');
        expect(DB::table('booking_cancellations')->count())->toBe(1);
    });

    it('records a no-show only once it has started, with the fee unless waived', function () {
        $id = ($this->booked)();

        ($this->as)($this->manager)->post("/admin/bookings/{$id}/no-show")->assertSessionHasErrors('booking');
        ($this->as)($this->manager)->get('/admin/bookings/'.$id)->assertDontSee('Record a no-show');
        $this->travelTo(CarbonImmutable::parse('2026-12-01 04:10:00', 'UTC'));
        ($this->as)($this->manager)->get('/admin/bookings/'.$id)->assertSee('Record a no-show')->assertSee('Mark as done');
        ($this->as)($this->manager)->post("/admin/bookings/{$id}/no-show", ['reason' => 'did not come'])->assertRedirect()->assertSessionHas('status');

        expect(($this->row)($id)->status)->toBe('no_show')->and((int) DB::table('booking_cancellations')->value('fee_minor'))->toBe(50000)->and((int) DB::table('booking_cancellations')->value('refund_due_minor'))->toBe(0);
    });

    it('lets staff waive the no-show fee', function () {
        $id = ($this->booked)();
        $this->travelTo(CarbonImmutable::parse('2026-12-01 04:10:00', 'UTC'));

        ($this->as)($this->manager)->post("/admin/bookings/{$id}/no-show", ['reason' => 'was ill', 'waive' => '1'])->assertRedirect();

        $row = DB::table('booking_cancellations')->first();
        expect(($this->row)($id)->status)->toBe('no_show')->and((int) $row->fee_minor)->toBe(0)->and((bool) $row->fee_waived)->toBeTrue()->and((int) $row->refund_due_minor)->toBe(50000);
    });

    it('marks it done once started', function () {
        $id = ($this->booked)();
        ($this->as)($this->manager)->post("/admin/bookings/{$id}/complete")->assertSessionHasErrors('booking');
        $this->travelTo(CarbonImmutable::parse('2026-12-01 04:30:00', 'UTC'));
        ($this->as)($this->manager)->post("/admin/bookings/{$id}/complete")->assertRedirect()->assertSessionHas('status');

        expect(($this->row)($id)->status)->toBe('completed')->and(($this->row)($id)->completed_by)->toBe('staff');
    });

    it('moves it any time with a reason, even past what a customer may, to a really free time', function () {
        $id = ($this->booked)();
        ($this->booked)('11:00');
        $this->travel(1)->minutes();   // too late for the customer

        ($this->as)($this->manager)->get('/admin/bookings/'.$id.'?date=2026-12-01')->assertOk()->assertSee('9:30 AM')->assertDontSee('value="'.($this->local)('11:00').'"', false);
        ($this->as)($this->manager)->post("/admin/bookings/{$id}/move", ['start' => ($this->local)('09:30'), 'reason' => ''])->assertSessionHasErrors('reason');
        ($this->as)($this->manager)->post("/admin/bookings/{$id}/move", ['start' => ($this->local)('11:00'), 'reason' => 'ask'])->assertSessionHasErrors('booking');
        ($this->as)($this->manager)->post("/admin/bookings/{$id}/move", ['start' => ($this->local)('09:30'), 'reason' => 'customer phoned'])->assertRedirect()->assertSessionHas('status', 'The booking was moved.');

        expect(CarbonImmutable::parse(($this->row)($id)->starts_at, 'UTC')->format('H:i'))->toBe('03:30')->and(DB::table('booking_events')->where('event', 'rescheduled')->value('note'))->toContain('staff override');
    });

    it('gives it to another person or room only if that one is free then', function () {
        $id = ($this->booked)();
        $busy = ($this->booked)('10:00', $this->room->public_id);

        ($this->as)($this->manager)->get('/admin/bookings/'.$id)->assertSee('No other person or room is free at this time.');
        ($this->as)($this->manager)->post("/admin/bookings/{$id}/reassign", ['resource' => $this->room->public_id])->assertSessionHasErrors('booking');
        ($this->as)($this->manager)->post("/admin/bookings/{$busy}/cancel", ['reason' => 'x']);
        ($this->as)($this->manager)->get('/admin/bookings/'.$id)->assertSee($this->room->name);
        ($this->as)($this->manager)->post("/admin/bookings/{$id}/reassign", ['resource' => $this->room->public_id])->assertRedirect()->assertSessionHas('status');

        expect((int) ($this->row)($id)->resource_id)->toBe($this->room->id)->and(DB::table('booking_events')->where('event', 'reassigned')->count())->toBe(1);
        ($this->as)($this->manager)->post("/admin/bookings/{$id}/reassign", ['resource' => 'bad'])->assertSessionHasErrors('resource');
    });

    it('reads in Bangla', function () {
        $id = ($this->booked)();
        $this->manager->forceFill(['locale' => 'bn'])->save();

        ($this->as)($this->manager)->get('/admin/bookings/'.$id)->assertOk()->assertSee('বুকিং বাতিল করুন')->assertSee('অন্যজনকে দিন')->assertSee('যে শর্তে এই বুকিং নিশ্চিত হয়েছে');
    });
});

describe('the calendar', function () {
    it('shows a day by person or room, and a week, with links to each booking, and filters by resource', function () {
        $a = ($this->booked)('10:00');
        $b = ($this->booked)('11:00', $this->room->public_id);

        $day = ($this->as)($this->viewer)->get('/admin/bookings/calendar?date=2026-12-01')->assertOk()->assertSee($this->tailor->name)->assertSee($this->room->name)->assertSee('10:00–10:30')->assertSee('/admin/bookings/'.$a, false)->assertSee('/admin/bookings/'.$b, false);
        expect(substr_count($day->getContent(), 'SVC-30'))->toBeGreaterThanOrEqual(2);
        ($this->as)($this->viewer)->get('/admin/bookings/calendar?date=2026-12-03&view=week')->assertOk()->assertSee('/admin/bookings/'.$a, false);   // the week of Monday 30 Nov
        ($this->as)($this->viewer)->get('/admin/bookings/calendar?date=2026-12-01&resource='.$this->room->public_id)->assertOk()->assertSee('/admin/bookings/'.$b, false)->assertDontSee('/admin/bookings/'.$a, false);
        ($this->as)($this->viewer)->get('/admin/bookings/calendar?date=2026-12-02')->assertOk()->assertDontSee('/admin/bookings/'.$a, false);
        $later = ($this->booked)('09:00');
        DB::table('service_bookings')->where('public_id', $later)->update(['starts_at' => DB::raw('starts_at + INTERVAL 7 DAY'), 'ends_at' => DB::raw('ends_at + INTERVAL 7 DAY'), 'blocks_from' => DB::raw('blocks_from + INTERVAL 7 DAY'), 'blocks_until' => DB::raw('blocks_until + INTERVAL 7 DAY')]);
        ($this->as)($this->viewer)->get('/admin/bookings/calendar?date=2026-12-01')->assertOk()->assertDontSee('/admin/bookings/'.$later, false);   // a week later is not on this day
        ($this->as)($this->viewer)->get('/admin/bookings/calendar?date=2026-12-01&view=week')->assertOk()->assertDontSee('/admin/bookings/'.$later, false);
        ($this->as)($this->viewer)->get('/admin/bookings/calendar?date=2026-12-08')->assertOk()->assertSee('/admin/bookings/'.$later, false);
        ($this->as)($this->viewer)->get('/admin/bookings/calendar?date=garbage&view=nonsense')->assertOk();
    });

    it('does not ask more questions for more bookings', function () {
        ($this->booked)('09:00');
        ($this->as)($this->viewer)->get('/admin/bookings/calendar?date=2026-12-01')->assertOk();
        DB::enableQueryLog();
        ($this->as)($this->viewer)->get('/admin/bookings/calendar?date=2026-12-01')->assertOk();
        $few = count(DB::getQueryLog());
        ($this->booked)('10:00');
        ($this->booked)('11:00', $this->room->public_id);
        DB::flushQueryLog();
        ($this->as)($this->viewer)->get('/admin/bookings/calendar?date=2026-12-01')->assertOk();

        expect(count(DB::getQueryLog()))->toBe($few);
    });

    it('is in the staff menu for those who may see bookings', function () {
        ($this->as)($this->viewer)->get('/admin/bookings')->assertSee('/admin/bookings/calendar', false);
        ($this->as)($this->nobody)->get('/admin')->assertDontSee('/admin/bookings/calendar', false);
    });
});
