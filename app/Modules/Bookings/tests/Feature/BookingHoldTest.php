<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\ExpireStaleHolds;
use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SaveServicePackage;
use App\Modules\Bookings\Actions\SaveServiceResource;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\Actions\MergeGuestCart;
use App\Modules\Cart\Actions\SetCartQuantity;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Exceptions\CartException;
use App\Modules\Cart\Services\GuestCartToken;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    bookingsWorld($this);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->seed(PriceListSeeder::class);
    app(SetRegularPrice::class)->handle($this->staff, $this->variant30, Money::of(50000));   // ৳500.00; SVC-60 deliberately has no price yet
    app(TransitionProduct::class)->publish($this->staff, Product::query()->whereHas('variants', fn ($q) => $q->whereKey($this->variant30))->firstOrFail());
    $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));   // Monday 10:00 in Dhaka
    $this->app->make(SaveServiceDefinition::class)->handle($this->staff, 'SVC-30', 'at_provider', 60, 60, 15, true);
    $this->app->make(SetWeeklyAvailability::class)->handle($this->staff, $this->tailor, [['weekday' => 2, 'start' => '09:00', 'end' => '12:00']]);
    $this->app->make(SetWeeklyAvailability::class)->handle($this->staff, $this->room, [['weekday' => 2, 'start' => '09:00', 'end' => '12:00']]);
    $this->alice = CartOwner::guest(str_repeat('a', 40));
    $this->bob = CartOwner::guest(str_repeat('b', 40));
    $this->at = fn (string $localTime, string $day = '2026-12-01') => CarbonImmutable::parse("{$day} {$localTime}", 'Asia/Dhaka')->utc()->format('Y-m-d H:i:s');
    $this->hold = fn (CartOwner $owner, string $localTime = '10:00', ?string $resource = null, string $sku = 'SVC-30', string $day = '2026-12-01') => app(HoldSlot::class)->handle($owner, $sku, $resource ?? $this->tailor->public_id, ($this->at)($localTime, $day));
    $this->refuses = fn (callable $call, string $reason) => expect($call)->toThrow(BookingException::class, $reason);
    $this->line = fn (CartOwner $owner) => DB::table('cart_items')->join('carts', 'carts.id', '=', 'cart_items.cart_id')->where($owner->userId !== null ? 'carts.user_id' : 'carts.guest_token_hash', $owner->userId ?? GuestCartToken::hash((string) $owner->guestToken))->first(['cart_items.*']);
    $this->events = fn (int $bookingId) => DB::table('booking_events')->where('booking_id', $bookingId)->orderBy('id')->pluck('event')->all();
});

describe('holding a time', function () {
    it('keeps the time for the visitor, puts the booking in their cart with the price, and records it', function () {
        $booking = ($this->hold)($this->alice);

        $line = ($this->line)($this->alice);
        expect($booking->status->value)->toBe('held')->and($booking->holder_key)->toBe($this->alice->key())->and($booking->resource_id)->toBe($this->tailor->id)->and($booking->starts_at->format('Y-m-d H:i'))->toBe('2026-12-01 04:00')
            ->and($booking->ends_at->format('H:i'))->toBe('04:30')->and($booking->blocks_until->format('H:i'))->toBe('04:40')   // the 10 minutes kept free after
            ->and($booking->hold_expires_at->equalTo(now()->addMinutes(15)))->toBeTrue()
            ->and((int) $line->quantity)->toBe(1)->and((int) $line->added_price_minor)->toBe(50000)->and($line->hold_ref)->toBe($booking->public_id)->and($line->variant_id)->toBe($this->variant30)
            ->and(($this->events)($booking->id))->toBe(['held']);
    });

    it('refuses a time the search would not offer, whatever the browser sends, and writes nothing', function () {
        ($this->refuses)(fn () => ($this->hold)($this->alice, '10:07'), 'slot_unavailable');   // not on the grid
        ($this->refuses)(fn () => ($this->hold)($this->alice, '08:00'), 'slot_unavailable');   // before the working time
        ($this->refuses)(fn () => ($this->hold)($this->alice, '11:45'), 'slot_unavailable');   // would end after it
        ($this->refuses)(fn () => ($this->hold)($this->alice, '10:00', null, 'SVC-30', '2026-11-30'), 'slot_unavailable');   // in the past
        ($this->refuses)(fn () => ($this->hold)($this->alice, '10:00', null, 'SVC-30', '2027-03-02'), 'slot_unavailable');   // beyond the horizon
        ($this->refuses)(fn () => ($this->hold)($this->alice, '10:00', $this->room->public_id, 'SVC-30', '2026-12-02'), 'slot_unavailable');   // a day nobody works
        ($this->refuses)(fn () => ($this->hold)($this->alice, '10:00', '01zzzzzzzzzzzzzzzzzzzzzzzz'), 'slot_unavailable');   // no such resource
        ($this->refuses)(fn () => app(HoldSlot::class)->handle($this->alice, 'SVC-30', $this->tailor->public_id, 'not a time'), 'time_invalid');
        app(SaveServiceResource::class)->handle($this->staff, $this->tailor, 'Rahim (tailor)', 'staff', false, ['SVC-30']);
        ($this->refuses)(fn () => ($this->hold)($this->alice), 'slot_unavailable');   // out of service

        expect(DB::table('service_bookings')->count())->toBe(0)->and(DB::table('cart_items')->count())->toBe(0);
    });

    it('refuses an unknown product, one that is not a service, and a service or package that is switched off', function () {
        ($this->refuses)(fn () => ($this->hold)($this->alice, '10:00', null, 'NOPE'), 'variant_unknown');
        ($this->refuses)(fn () => ($this->hold)($this->alice, '10:00', null, 'TEE-1'), 'package_unavailable');
        app(SaveServicePackage::class)->handle($this->staff, 'SVC-30', 30, 0, 10, false);
        ($this->refuses)(fn () => ($this->hold)($this->alice), 'package_unavailable');
        expect(DB::table('service_bookings')->count())->toBe(0);
    });

    it('lets only one visitor have a time: the next one is told it is gone, and another resource is still free', function () {
        ($this->hold)($this->alice);

        ($this->refuses)(fn () => ($this->hold)($this->bob), 'slot_unavailable');
        $other = ($this->hold)($this->bob, '10:00', $this->room->public_id);

        expect($other->resource_id)->toBe($this->room->id)->and(DB::table('service_bookings')->count())->toBe(2);
    });

    it('replaces a visitor\'s own hold when they choose another time for the same service', function () {
        $first = ($this->hold)($this->alice, '09:00');
        $second = ($this->hold)($this->alice, '10:30');

        expect(DB::table('service_bookings')->where('id', $first->id)->value('status'))->toBe('cancelled')->and(($this->events)($first->id))->toBe(['held', 'hold_replaced'])->and($second->status->value)->toBe('held')
            ->and(DB::table('cart_items')->count())->toBe(1)->and(($this->line)($this->alice)->hold_ref)->toBe($second->public_id);
        expect(($this->hold)($this->bob, '09:00')->status->value)->toBe('held');   // the time the first visitor gave up is free for somebody else
    });

    it('lets a visitor hold at most three times at once', function () {
        foreach ([2, 3, 4] as $i) {
            $id = ($this->book)($this->room->id, $this->package60->id, 'held', '2026-12-0'.$i.' 03:00', 60);   // other service: a new choice of SVC-30 would not replace these
            DB::table('service_bookings')->where('id', $id)->update(['holder_key' => $this->alice->key(), 'hold_expires_at' => now()->addMinutes(10)]);
        }

        ($this->refuses)(fn () => ($this->hold)($this->alice), 'too_many_holds');
        expect(($this->hold)($this->bob)->status->value)->toBe('held')->and(DB::table('service_bookings')->where('holder_key', $this->alice->key())->count())->toBe(3);   // somebody else is not affected
    });

    it('clears a hold that ran out on the same time before it tries, and says so in that booking\'s history', function () {
        $stale = ($this->hold)($this->alice);
        $this->travelTo(now()->addMinutes(20));   // the hold has lapsed, but nobody has swept it yet
        expect(DB::table('service_bookings')->where('id', $stale->id)->value('status'))->toBe('held');

        $new = ($this->hold)($this->bob);

        expect($new->status->value)->toBe('held')->and(DB::table('service_bookings')->where('id', $stale->id)->value('status'))->toBe('cancelled')->and(($this->events)($stale->id))->toBe(['held', 'hold_expired']);
    });

    it('leaves nothing held when the booking cannot go in the cart, for example the service has no price', function () {
        app(SaveServiceDefinition::class)->handle($this->staff, 'SVC-60', 'online', 0, 60, 15, true);
        app(SetWeeklyAvailability::class)->handle($this->staff, $this->room, [['weekday' => 2, 'start' => '09:00', 'end' => '12:00']]);

        ($this->refuses)(fn () => ($this->hold)($this->alice, '10:00', $this->room->public_id, 'SVC-60'), 'not_for_sale');

        expect(DB::table('service_bookings')->count())->toBe(0)->and(DB::table('cart_items')->count())->toBe(0);
    });
});

describe('holds that run out', function () {
    it('are ended by the sweeper, which leaves valid holds and confirmed bookings alone, writes it down, and is safe to repeat', function () {
        $live = ($this->hold)($this->alice, '09:00');
        $gone = ($this->hold)($this->bob, '10:00');
        $confirmed = ($this->book)($this->room->id, $this->package30->id, 'confirmed', '2026-12-01 04:00', 30);
        DB::table('service_bookings')->where('id', $gone->id)->update(['hold_expires_at' => now()->subMinute()]);

        expect(app(ExpireStaleHolds::class)->handle())->toBe(1)->and(app(ExpireStaleHolds::class)->handle())->toBe(0);

        expect(DB::table('service_bookings')->where('id', $gone->id)->value('status'))->toBe('cancelled')->and(($this->events)($gone->id))->toBe(['held', 'hold_expired'])
            ->and(DB::table('service_bookings')->where('id', $live->id)->value('status'))->toBe('held')->and(DB::table('service_bookings')->where('id', $confirmed)->value('status'))->toBe('confirmed');
        expect(($this->hold)($this->alice, '10:00', null, 'SVC-30')->status->value)->toBe('held');   // the time is free again
    });

    it('is run every minute by the scheduler', function () {
        Artisan::call('schedule:list');

        expect(Artisan::output())->toMatch('/\*\s+\*\s+\*\s+\*\s+\*\s+bookings:expire-holds/');
    });
});

describe('the cart', function () {
    it('refuses a service added the ordinary way, and lets other products in as before', function () {
        expect(fn () => app(AddToCart::class)->handle($this->alice, $this->variant30, 1))->toThrow(CartException::class, 'needs_booking');
        expect(DB::table('cart_items')->count())->toBe(0);
    });

    it('keeps a booking at one, lets it be removed, and carries it from a guest cart to the customer\'s account', function () {
        $booking = ($this->hold)($this->alice);
        $set = fn (int $qty) => app(SetCartQuantity::class)->handle($this->alice, $this->variant30, $qty);

        expect(fn () => $set(2))->toThrow(CartException::class, 'one_booking');
        $set(1);
        expect((int) ($this->line)($this->alice)->quantity)->toBe(1);

        $user = User::factory()->create(['email' => 'c@example.com']);
        app(MergeGuestCart::class)->handle($user->id, (string) $this->alice->guestToken);
        expect(($this->line)(CartOwner::user($user->id))->hold_ref)->toBe($booking->public_id)->and(DB::table('cart_items')->count())->toBe(1);

        $set2 = app(SetCartQuantity::class);
        $set2->handle(CartOwner::user($user->id), $this->variant30, 0);
        expect(DB::table('cart_items')->count())->toBe(0);
    });

    it('keeps the signed-in customer\'s own choice when a guest cart with another slot joins theirs', function () {
        $user = User::factory()->create(['email' => 'c@example.com']);
        $mine = ($this->hold)(CartOwner::user($user->id), '09:00');
        ($this->hold)($this->alice, '10:30');

        app(MergeGuestCart::class)->handle($user->id, (string) $this->alice->guestToken);

        expect(($this->line)(CartOwner::user($user->id))->hold_ref)->toBe($mine->public_id)->and(DB::table('cart_items')->count())->toBe(1);
    });
});

describe('the page where a customer chooses', function () {
    it('shows the day\'s free times with the price, to anyone, in the shop\'s clock', function () {
        $this->get('/en/book/SVC-30?date=2026-12-01')->assertOk()->assertSee('Book SVC-30')->assertSee('30 minutes')->assertSee('500')->assertSee('At our place')->assertSee('9:00')->assertSee('Rahim (tailor)')->assertSee('Fitting room')->assertSee('Dhaka time');
        $this->get('/en/book/SVC-30?date=2026-12-02')->assertOk()->assertSee('There are no free times on this day');
        $this->get('/en/book/SVC-30?date=2027-06-01')->assertOk()->assertSee('There are no free times on this day');   // past the horizon
        $this->get('/en/book/SVC-30?date=banana')->assertOk()->assertSee('Enter a date like');
        $this->get('/en/book/SVC-30')->assertOk();   // no date: tomorrow
    });

    it('answers not found for anything that cannot be booked', function () {
        $this->get('/en/book/NOPE')->assertNotFound();
        $this->get('/en/book/TEE-1')->assertNotFound();
        app(SaveServiceDefinition::class)->handle($this->staff, 'SVC-30', 'at_provider', 60, 60, 15, false);
        $this->get('/en/book/SVC-30')->assertNotFound();
    });

    it('holds a time when a visitor presses it, gives them a cart, and says so when someone took it first', function () {
        $token = str_repeat('c', 40);
        $start = ($this->at)('09:00');
        $post = fn (string $resource, string $startUtc, ?string $t = null) => $this->withCookie(GuestCartToken::COOKIE, $t ?? $token)->post('/en/book/SVC-30/hold', ['resource' => $resource, 'start' => $startUtc]);

        $post($this->tailor->public_id, $start)->assertRedirect('/en/cart')->assertSessionHas('status', 'Your time is held. Finish checkout to keep it.');
        $this->withCookie(GuestCartToken::COOKIE, $token)->get('/en/cart')->assertOk()->assertSee('SVC-30')->assertSee('500');
        $post($this->tailor->public_id, $start, str_repeat('d', 40))->assertSessionHasErrors(['slot' => 'That time is no longer available. Please choose another.']);   // someone else, same time
        $post('nonsense', $start)->assertSessionHasErrors('resource');
        $post($this->tailor->public_id, '')->assertSessionHasErrors('start');

        expect(DB::table('service_bookings')->where('status', 'held')->count())->toBe(1);
    });

    it('gives a first-time visitor a cart cookie when they press a time', function () {
        $response = $this->post('/en/book/SVC-30/hold', ['resource' => $this->tailor->public_id, 'start' => ($this->at)('09:00')]);

        $response->assertRedirect('/en/cart')->assertCookie(GuestCartToken::COOKIE);
        expect(DB::table('service_bookings')->where('status', 'held')->count())->toBe(1);
    });

    it('is limited per visitor', function () {
        $statuses = [];
        foreach (range(1, 22) as $i) {
            $statuses[] = $this->post('/en/book/SVC-30/hold', ['resource' => $this->tailor->public_id, 'start' => ($this->at)('09:00')])->getStatusCode();
        }

        expect($statuses[0])->toBe(302)->and(end($statuses))->toBe(429);
    });

    it('is offered on the product page of a service instead of "add to cart"', function () {
        app(SetRegularPrice::class)->handle($this->staff, $this->tee, Money::of(65000));
        app(TransitionProduct::class)->publish($this->staff, Product::query()->whereHas('variants', fn ($q) => $q->whereKey($this->tee))->firstOrFail());
        $slug = fn (int $variant) => (string) DB::table('product_translations as t')->join('product_variants as v', 'v.product_id', '=', 't.product_id')->where('v.id', $variant)->where('t.locale', 'en')->value('t.slug');

        $this->get('/en/p/'.$slug($this->variant30))->assertOk()->assertSee('Choose a time')->assertSee('/en/book/SVC-30', false)->assertDontSee('Add to cart');
        $this->get('/en/p/'.$slug($this->tee))->assertOk()->assertDontSee('Choose a time')->assertSee('Add to cart');
    });

    it('is in Bangla too, with no missing text', function () {
        $html = $this->get('/bn/book/SVC-30?date=2026-12-01')->assertOk()->assertSee('বুক করুন')->assertSee('সময়')->getContent();

        expect($html)->not->toContain('bookings::');
        $this->post('/bn/book/SVC-30/hold', ['resource' => $this->tailor->public_id, 'start' => ($this->at)('08:00')])->assertSessionHasErrors(['slot' => 'ওই সময়টি আর পাওয়া যাচ্ছে না। অন্য একটি বেছে নিন।']);
    });
});
