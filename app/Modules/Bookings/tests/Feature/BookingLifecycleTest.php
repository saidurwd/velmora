<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\AutoCompleteBookings;
use App\Modules\Bookings\Actions\CancelBooking;
use App\Modules\Bookings\Actions\CompleteBooking;
use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Actions\MarkNoShow;
use App\Modules\Bookings\Actions\RescheduleBooking;
use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SendBookingReminders;
use App\Modules\Bookings\Actions\SendReviewPrompts;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Bookings\Actions\StartBookings;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Jobs\RunBookingLifecycleJob;
use App\Modules\Bookings\Mail\BookingMail;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Sms\FakeSmsGateway;
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
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    bookingsWorld($this);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->seed([PriceListSeeder::class, PaymentMethodSeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    config(['services.sms.driver' => 'fake']);
    $this->sms = app(FakeSmsGateway::class);
    Mail::fake();
    app(SetRegularPrice::class)->handle($this->staff, $this->variant30, Money::of(50000));
    app(TransitionProduct::class)->publish($this->staff, Product::query()->whereHas('variants', fn ($q) => $q->whereKey($this->variant30))->firstOrFail());
    $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));   // exactly 24 hours before a 10:00 Dhaka start on Tuesday 1 December
    app(SaveServiceDefinition::class)->handle($this->staff, 'SVC-30', 'at_provider', 60, 60, 15, true);
    foreach ([$this->tailor, $this->room] as $resource) {
        app(SetWeeklyAvailability::class)->handle($this->staff, $resource, [['weekday' => 2, 'start' => '09:00', 'end' => '12:00']]);
    }
    $this->owner = User::factory()->create(['email' => 'o@example.com']);
    $this->local = fn (string $t) => CarbonImmutable::parse("2026-12-01 {$t}", 'Asia/Dhaka')->utc()->format('Y-m-d H:i:s');

    /** A paid, confirmed booking at the given local time. Returns its public id. */
    $this->booked = function (string $local = '10:00', string $locale = 'en', ?string $email = 'rahim@example.com', ?User $user = null, ?string $resource = null) {
        $user ??= $this->owner;
        $owner = CartOwner::user($user->id);
        app(HoldSlot::class)->handle($owner, 'SVC-30', $resource ?? $this->tailor->public_id, ($this->local)($local));
        $pay = 'online';
        $order = app(PlaceOrder::class)->handle(new PlaceOrderCommand(
            actor: Actor::guest('checkout'), owner: $owner, userId: $user->id, contactName: 'Rahim Uddin', contactPhone: '01712345678', contactEmail: $email, address: null, shippingMethodCode: null, paymentMethodCode: $pay,
            expectedTotalMinor: app(CheckoutPricing::class)->quote(app(CartReader::class)->view($owner, $locale), null, null, $pay, $locale)->breakdown->grandTotalMinor, placeToken: 'token-'.Str::random(12), locale: $locale,
        ));
        app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Confirmed, 'test');
        app(TransitionOrder::class)->payment(Actor::system('t'), $order->id, PaymentStatus::Paid, 'test');
        app(OutboxDispatcher::class)->dispatchDue();

        return (string) DB::table('service_bookings')->where('order_item_id', DB::table('order_items')->where('order_id', $order->id)->value('id'))->value('public_id');
    };
    $this->texts = fn () => array_values(array_filter($this->sms->sent(), fn (array $m) => str_starts_with((string) ($m['meta']['purpose'] ?? ''), 'booking_')));   // not the order confirmation
    $this->row = fn (string $publicId) => DB::table('service_bookings')->where('public_id', $publicId)->first();
    $this->events = fn (string $publicId) => DB::table('booking_events')->where('booking_id', ($this->row)($publicId)->id)->orderBy('id')->pluck('event')->all();
    $this->notices = fn () => DB::table('booking_notices')->orderBy('id')->get(['kind', 'channel', 'status', 'attempts'])->map(fn ($r) => "{$r->kind}/{$r->channel}/{$r->status}/{$r->attempts}")->all();
    $this->refuses = fn (callable $call, string $reason) => expect($call)->toThrow(BookingException::class, $reason);
});

describe('reminders', function () {
    it('sends the first reminder 24 hours before by SMS and email, in Dhaka time, once however often it runs', function () {
        ($this->booked)();

        expect(app(SendBookingReminders::class)->handle())->toBe(2)->and(app(SendBookingReminders::class)->handle())->toBe(0);

        $sms = ($this->texts)();
        expect($sms)->toHaveCount(1)->and($sms[0]['to'])->toBe('+8801712345678')->and($sms[0]['message'])->toContain('SVC-30')->and($sms[0]['message'])->toContain('1 Dec 2026, 10:00 AM')->and($sms[0]['message'])->toContain('at our place')
            ->and($sms[0]['meta'])->toBe(['purpose' => 'booking_reminder_long'])->and(($this->notices)())->toBe(['reminder_long/sms/sent/1', 'reminder_long/email/sent/1']);
        Mail::assertSent(BookingMail::class, fn (BookingMail $m) => $m->hasTo('rahim@example.com') && str_contains($m->body, '10:00 AM'));
        Mail::assertSent(BookingMail::class, 1);
    });

    it('waits until 24 hours before, and sends only the last reminder to a booking made inside the last 2 hours', function () {
        ($this->booked)();
        $this->travelTo(CarbonImmutable::parse('2026-11-30 03:59:00', 'UTC'));
        expect(app(SendBookingReminders::class)->handle())->toBe(0);

        $this->travelTo(CarbonImmutable::parse('2026-12-01 02:30:00', 'UTC'));   // 90 minutes before: the short window only
        expect(app(SendBookingReminders::class)->handle())->toBe(2)->and(array_map(fn ($m) => $m['meta']['purpose'], ($this->texts)()))->toBe(['booking_reminder_short'])
            ->and(($this->notices)())->toBe(['reminder_short/sms/sent/1', 'reminder_short/email/sent/1']);
    });

    it('sends the second reminder 2 hours before, after the first', function () {
        ($this->booked)();
        app(SendBookingReminders::class)->handle();
        $this->travelTo(CarbonImmutable::parse('2026-12-01 02:00:00', 'UTC'));

        expect(app(SendBookingReminders::class)->handle())->toBe(2)->and(array_map(fn ($m) => $m['meta']['purpose'], ($this->texts)()))->toBe(['booking_reminder_long', 'booking_reminder_short']);
    });

    it('does not remind a booking that is cancelled, only held, not ordered, or already started', function () {
        $cancelled = ($this->booked)('09:00');
        app(CancelBooking::class)->handle($this->staff, $cancelled, 'x');
        app(HoldSlot::class)->handle(CartOwner::guest(str_repeat('b', 40)), 'SVC-30', $this->tailor->public_id, ($this->local)('11:00'));   // held, not ordered
        $started = ($this->booked)('10:00');
        DB::table('service_bookings')->where('public_id', $started)->update(['status' => 'in_progress']);

        expect(app(SendBookingReminders::class)->handle())->toBe(0)->and(($this->texts)())->toBe([])->and(($this->notices)())->toBe([]);
    });

    it('reminds a moved booking again, for its new time, and still only once for each time', function () {
        $id = ($this->booked)();
        app(SendBookingReminders::class)->handle();
        app(RescheduleBooking::class)->handle($this->staff, $id, ($this->local)('09:30'), true, 'asked');   // earlier, so still inside the 24 hours

        expect(app(SendBookingReminders::class)->handle())->toBe(2)->and(app(SendBookingReminders::class)->handle())->toBe(0)
            ->and(DB::table('booking_notices')->count())->toBe(4)->and(str_contains(($this->texts)()[1]['message'], '09:30 AM'))->toBeTrue();
    });

    it('uses the language of the order, and sends only an SMS when the order has no email', function () {
        ($this->booked)('10:00', 'bn', null);

        expect(app(SendBookingReminders::class)->handle())->toBe(1)->and(($this->texts)()[0]['message'])->toContain('অনুস্মারক')->and(($this->texts)()[0]['message'])->toContain('আমাদের এখানে')
            ->and(($this->notices)())->toBe(['reminder_long/sms/sent/1']);
        Mail::assertNotSent(BookingMail::class);
    });

    it('follows the shop\'s settings for how long before', function () {
        ($this->booked)();
        app(Settings::class)->set('bookings.reminder_long_minutes', 60);

        expect(app(SendBookingReminders::class)->handle())->toBe(0);
        app(Settings::class)->set('bookings.reminder_long_minutes', 2000);
        $this->travelTo(CarbonImmutable::parse('2026-11-29 04:00:00', 'UTC'));   // 48 hours before
        expect(app(SendBookingReminders::class)->handle())->toBe(0);
        $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));
        expect(app(SendBookingReminders::class)->handle())->toBe(2);
    });
});

describe('a message that fails', function () {
    it('is tried again on the next runs, three times in all, then given up, and never sent twice once it works', function () {
        Log::spy();
        ($this->booked)('10:00', 'en', null);
        $this->sms->fail = true;

        expect(app(SendBookingReminders::class)->handle())->toBe(0)->and(($this->notices)())->toBe(['reminder_long/sms/failed/1']);
        expect(app(SendBookingReminders::class)->handle())->toBe(0)->and(($this->notices)())->toBe(['reminder_long/sms/failed/2']);
        $this->sms->fail = false;
        expect(app(SendBookingReminders::class)->handle())->toBe(1)->and(($this->notices)())->toBe(['reminder_long/sms/sent/3'])->and(app(SendBookingReminders::class)->handle())->toBe(0);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => $m === 'bookings.notice_failed' && ! str_contains(json_encode($c), '0171'))->twice();
    });

    it('gives up after the last attempt', function () {
        ($this->booked)('10:00', 'en', null);
        $this->sms->fail = true;

        foreach (range(1, 5) as $_) {
            app(SendBookingReminders::class)->handle();
        }

        expect(($this->notices)())->toBe(['reminder_long/sms/gave_up/3'])->and(DB::table('booking_notices')->value('last_error'))->toBe(RuntimeException::class);
    });

    it('is taken over when a worker died holding it, but not before ten minutes', function () {
        $id = ($this->booked)('10:00', 'en', null);
        DB::table('booking_notices')->insert(['booking_id' => ($this->row)($id)->id, 'kind' => 'reminder_long', 'channel' => 'sms', 'for_starts_at' => ($this->row)($id)->starts_at, 'status' => 'claimed', 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);

        expect(app(SendBookingReminders::class)->handle())->toBe(0);
        $this->travel(9)->minutes();
        expect(app(SendBookingReminders::class)->handle())->toBe(0)->and(($this->notices)())->toBe(['reminder_long/sms/claimed/1']);   // still within ten minutes
        $this->travel(2)->minutes();
        expect(app(SendBookingReminders::class)->handle())->toBe(1)->and(($this->notices)())->toBe(['reminder_long/sms/sent/2']);
    });

    it('keeps only the outcome: nothing else about a notice can change, and none can be removed', function () {
        ($this->booked)();
        app(SendBookingReminders::class)->handle();

        expect(fn () => DB::table('booking_notices')->update(['kind' => 'review']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('booking_notices')->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('booking_notices')->insert(['booking_id' => 1, 'kind' => 'other', 'channel' => 'sms', 'for_starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
    });
});

describe('a booking under way', function () {
    it('starts when its time comes, not before, and only once', function () {
        $id = ($this->booked)();
        expect(app(StartBookings::class)->handle())->toBe(0)->and(($this->row)($id)->status)->toBe('confirmed');

        $this->travelTo(CarbonImmutable::parse('2026-12-01 04:00:00', 'UTC'));
        expect(app(StartBookings::class)->handle())->toBe(1)->and(app(StartBookings::class)->handle())->toBe(0)->and(($this->row)($id)->status)->toBe('in_progress')->and(array_slice(($this->events)($id), -1))->toBe(['started']);
    });

    it('is still its resource\'s time, and can still be a no-show', function () {
        $id = ($this->booked)();
        $this->travelTo(CarbonImmutable::parse('2026-12-01 04:10:00', 'UTC'));
        app(StartBookings::class)->handle();

        expect(fn () => app(HoldSlot::class)->handle(CartOwner::guest(str_repeat('c', 40)), 'SVC-30', $this->tailor->public_id, ($this->local)('10:15')))->toThrow(BookingException::class);
        app(MarkNoShow::class)->handle($this->staff, $id, 'did not come');
        expect(($this->row)($id)->status)->toBe('no_show');
    });
});

describe('completing a booking', function () {
    it('is done by staff or by the customer, once it has started, and records who', function () {
        $mine = ($this->booked)('09:00');
        $theirs = ($this->booked)('11:00', 'en', null, User::factory()->create(['email' => 'b@example.com']));
        $customer = new Actor(ActorType::Customer, $this->owner->id);

        ($this->refuses)(fn () => app(CompleteBooking::class)->handle($customer, $mine), 'not_started');
        $this->travelTo(CarbonImmutable::parse('2026-12-01 03:30:00', 'UTC'));   // the 09:00 booking has begun
        app(StartBookings::class)->handle();
        ($this->refuses)(fn () => app(CompleteBooking::class)->handle($customer, $theirs), 'not_found');
        app(CompleteBooking::class)->handle($customer, $mine);

        $row = ($this->row)($mine);
        expect($row->status)->toBe('completed')->and($row->completed_by)->toBe('customer')->and($row->completed_at)->not->toBeNull()->and(array_slice(($this->events)($mine), -1))->toBe(['completed']);
        ($this->refuses)(fn () => app(CompleteBooking::class)->handle($customer, $mine), 'not_completable');

        $this->travelTo(CarbonImmutable::parse('2026-12-01 05:30:00', 'UTC'));
        app(CompleteBooking::class)->handle($this->staff, $theirs);   // still "confirmed" is fine for staff once it has started
        expect(($this->row)($theirs)->completed_by)->toBe('staff');
    });

    it('cannot be done for a cancelled booking or by a guest', function () {
        $id = ($this->booked)();
        $this->travelTo(CarbonImmutable::parse('2026-12-01 04:30:00', 'UTC'));

        ($this->refuses)(fn () => app(CompleteBooking::class)->handle(Actor::guest('x'), $id), 'not_found');
        DB::table('service_bookings')->where('public_id', $id)->update(['status' => 'cancelled']);
        ($this->refuses)(fn () => app(CompleteBooking::class)->handle($this->staff, $id), 'not_completable');
    });

    it('is done by the system for a running booking nobody completed, a day after it ended, unless switched off', function () {
        $id = ($this->booked)();
        $this->travelTo(CarbonImmutable::parse('2026-12-01 04:10:00', 'UTC'));
        app(StartBookings::class)->handle();   // ends 04:30

        $this->travelTo(CarbonImmutable::parse('2026-12-02 04:29:00', 'UTC'));
        expect(app(AutoCompleteBookings::class)->handle())->toBe(0);
        app(Settings::class)->set('bookings.auto_complete_hours', 0);
        $this->travelTo(CarbonImmutable::parse('2026-12-02 04:31:00', 'UTC'));
        expect(app(AutoCompleteBookings::class)->handle())->toBe(0);
        app(Settings::class)->set('bookings.auto_complete_hours', 24);
        expect(app(AutoCompleteBookings::class)->handle())->toBe(1)->and(app(AutoCompleteBookings::class)->handle())->toBe(0);

        $row = ($this->row)($id);
        expect($row->status)->toBe('completed')->and($row->completed_by)->toBe('system')->and(array_slice(($this->events)($id), -1))->toBe(['auto_completed']);
    });
});

describe('asking how it went', function () {
    it('is done once after completion, with a link to the service, and not for old or unfinished bookings', function () {
        $id = ($this->booked)();
        $old = ($this->booked)('11:00');
        $this->travelTo(CarbonImmutable::parse('2026-12-01 05:30:00', 'UTC'));
        expect(app(SendReviewPrompts::class)->handle())->toBe(0);   // nothing completed yet
        app(CompleteBooking::class)->handle($this->staff, $id);
        app(CompleteBooking::class)->handle($this->staff, $old);
        DB::table('service_bookings')->where('public_id', $old)->update(['completed_at' => now()->subDays(8)]);

        expect(app(SendReviewPrompts::class)->handle())->toBe(2)->and(app(SendReviewPrompts::class)->handle())->toBe(0);

        $sms = ($this->texts)();
        expect($sms)->toHaveCount(1)->and($sms[0]['message'])->toContain('/en/p/')->and($sms[0]['meta'])->toBe(['purpose' => 'booking_review']);
        Mail::assertSent(BookingMail::class, fn (BookingMail $m) => str_contains((string) $m->link, '/en/p/'));
    });
});

describe('the schedule', function () {
    it('runs the lifecycle every five minutes, and one run does all four steps', function () {
        Artisan::call('schedule:list');
        expect(Artisan::output())->toMatch('~\*/5\s+\*\s+\*\s+\*\s+\*\s+bookings:lifecycle~');

        $id = ($this->booked)();
        app(RunBookingLifecycleJob::class)->handle(app(StartBookings::class), app(AutoCompleteBookings::class), app(SendBookingReminders::class), app(SendReviewPrompts::class));
        expect(DB::table('booking_notices')->count())->toBe(2)->and(($this->row)($id)->status)->toBe('confirmed');
    });
});
