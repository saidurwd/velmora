<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\SaveHoliday;
use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SaveServicePackage;
use App\Modules\Bookings\Actions\SaveServiceResource;
use App\Modules\Bookings\Actions\SetAvailabilityException;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Bookings\DTOs\Slot;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Services\SlotSearch;
use App\Modules\Platform\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    bookingsWorld($this);
    // "now" is Monday 30 Nov 2026, 10:00 in Dhaka (04:00 UTC); the days looked at are Tuesday 1 Dec onwards.
    $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));
    $this->week = fn ($resource, array $windows) => app(SetWeeklyAvailability::class)->handle($this->staff, $resource, $windows);
    $this->open = fn ($resource = null, string $start = '09:00', string $end = '12:00', int $weekday = 2) => ($this->week)($resource ?? $this->tailor, [['weekday' => $weekday, 'start' => $start, 'end' => $end]]);
    $this->find = fn (string $sku = 'SVC-30', string $from = '2026-12-01', ?string $to = null, ?string $resource = null) => app(SlotSearch::class)->forVariant($sku, $from, $to ?? $from, $resource);
    $this->times = fn (array $slots) => array_map(fn (Slot $s) => $s->localStart()->format('H:i'), $slots);
    $this->define = fn (string $sku = 'SVC-30', string $location = 'at_provider', int $lead = 60, int $horizon = 60, int $hold = 15, bool $active = true) => app(SaveServiceDefinition::class)->handle($this->staff, $sku, $location, $lead, $horizon, $hold, $active);
});

describe('what is offered on a working day', function () {
    it('offers a start every fifteen minutes while the whole service fits in the working time, with the right UTC times', function () {
        ($this->open)();

        $slots = ($this->find)();

        expect($slots)->toHaveCount(11)->and(($this->times)($slots)[0])->toBe('09:00')->and(($this->times)($slots)[10])->toBe('11:30')   // 11:30 + 30 minutes ends exactly at 12:00
            ->and($slots[0]->startsAt->format('Y-m-d H:i'))->toBe('2026-12-01 03:00')->and($slots[0]->endsAt->format('H:i'))->toBe('03:30')->and($slots[0]->localEnd()->format('H:i'))->toBe('09:30')
            ->and($slots[0]->resourceName)->toBe('Rahim (tailor)')->and($slots[0]->localDate())->toBe('2026-12-01');
    });

    it('offers nothing on a day the resource does not work, and uses Monday = 1 to Sunday = 7', function () {
        ($this->open)(null, '09:00', '12:00', 2);   // Tuesday only

        expect(($this->find)('SVC-30', '2026-12-02'))->toBe([])->and(($this->find)('SVC-30', '2026-12-01'))->not->toBe([]);
        ($this->open)(null, '09:00', '12:00', 7);   // Sunday
        expect(($this->find)('SVC-30', '2026-12-06'))->not->toBe([])->and(($this->find)('SVC-30', '2026-12-01'))->toBe([]);
    });

    it('offers each working window of the day and nothing between them', function () {
        ($this->week)($this->tailor, [['weekday' => 2, 'start' => '09:00', 'end' => '10:00'], ['weekday' => 2, 'start' => '14:00', 'end' => '15:00']]);

        expect(($this->times)(($this->find)()))->toBe(['09:00', '09:15', '09:30', '14:00', '14:15', '14:30']);
    });

    it('follows the step the shop sets', function () {
        ($this->open)(null, '09:00', '11:00');
        app(Settings::class)->set('bookings.slot_step_minutes', 30);

        expect(($this->times)(($this->find)()))->toBe(['09:00', '09:30', '10:00', '10:30']);
    });

    it('offers a longer package only where it fits, on the resources that serve it', function () {
        ($this->open)($this->tailor, '09:00', '10:00');
        ($this->open)($this->room, '09:00', '10:30');

        $slots = ($this->find)('SVC-60');

        expect(array_unique(array_map(fn (Slot $s) => $s->resourceName, $slots)))->toBe([0 => 'Fitting room'])   // the tailor does not serve SVC-60
            ->and(($this->times)($slots))->toBe(['09:00', '09:15', '09:30']);   // a 60-minute booking must end by 10:30
    });
});

describe('days that are not like the week', function () {
    it('closes a day, gives a day other hours, and holidays close the whole shop', function () {
        ($this->week)($this->tailor, [['weekday' => 2, 'start' => '09:00', 'end' => '10:00']]);
        ($this->week)($this->room, [['weekday' => 2, 'start' => '09:00', 'end' => '10:00']]);
        app(SetAvailabilityException::class)->set($this->staff, $this->tailor, '2026-12-01', true, null, null, null);

        expect(array_unique(array_map(fn (Slot $s) => $s->resourceName, ($this->find)())))->toBe([0 => 'Fitting room']);   // the room still works

        app(SetAvailabilityException::class)->set($this->staff, $this->tailor, '2026-12-01', false, '13:00', '14:00', null);
        expect(($this->times)(($this->find)('SVC-30', '2026-12-01', null, $this->tailor->public_id)))->toBe(['13:00', '13:15', '13:30']);   // the weekly 09-10 no longer applies that day

        app(SaveHoliday::class)->set($this->staff, '2026-12-01', 'Test holiday');
        expect(($this->find)())->toBe([]);
    });

    it('applies a timetable only between its dates', function () {
        ($this->week)($this->tailor, [['weekday' => 2, 'start' => '09:00', 'end' => '10:00', 'valid_from' => '2026-12-08', 'valid_to' => '2026-12-15']]);

        expect(($this->find)('SVC-30', '2026-12-01'))->toBe([])->and(($this->find)('SVC-30', '2026-12-08'))->not->toBe([])->and(($this->find)('SVC-30', '2026-12-15'))->not->toBe([])->and(($this->find)('SVC-30', '2026-12-22'))->toBe([]);
    });
});

describe('how soon and how far ahead', function () {
    it('offers nothing sooner than the lead time from now', function () {
        ($this->define)('SVC-30', 'at_provider', 120, 60);   // two hours' notice
        ($this->open)(null, '09:00', '12:00', 1);   // Monday: today
        $this->travelTo(CarbonImmutable::parse('2026-11-30 03:30:00', 'UTC'));   // 09:30 in Dhaka

        expect(($this->times)(($this->find)('SVC-30', '2026-11-30')))->toBe(['11:30']);   // earliest 11:30; and 11:30 + 30 minutes still ends by 12:00
    });

    it('offers nothing past the horizon, and nothing in the past', function () {
        ($this->define)('SVC-30', 'at_provider', 0, 3);   // three days ahead
        foreach ([1, 2, 3, 4, 5, 6, 7] as $weekday) {
            ($this->week)($this->tailor, array_map(fn ($d) => ['weekday' => $d, 'start' => '09:00', 'end' => '09:30'], [1, 2, 3, 4, 5, 6, 7]));
        }
        $days = array_unique(array_map(fn (Slot $s) => $s->localDate(), ($this->find)('SVC-30', '2026-11-29', '2026-12-10')));

        expect(array_values($days))->toBe(['2026-12-01', '2026-12-02', '2026-12-03']);   // not yesterday, not today's 09:00 (already past at 10:00), and nothing after now + 3 days
    });

    it('offers nothing when the service or the package is switched off', function () {
        ($this->open)();
        expect(($this->find)())->not->toBe([]);

        ($this->define)('SVC-30', 'at_provider', 60, 60, 15, false);
        expect(($this->find)())->toBe([]);
        ($this->define)();
        app(SaveServicePackage::class)->handle($this->staff, 'SVC-30', 30, 0, 10, false);
        expect(($this->find)())->toBe([]);
    });
});

describe('what is already taken', function () {
    it('leaves out a time whose service plus buffers would touch another booking, on both sides', function () {
        ($this->open)(null, '09:00', '12:00');
        ($this->book)($this->tailor->id, $this->package30->id, 'confirmed', '2026-12-01 04:00', 30);   // 10:00-10:30 in Dhaka, kept free until 10:40

        $times = ($this->times)(($this->find)());

        expect($times)->toBe(['09:00', '09:15', '10:45', '11:00', '11:15', '11:30'])   // 09:30 and 09:45 would end (with buffer) inside it; 10:30 and 10:45 start too soon
            ->not->toContain('10:30');
    });

    it('counts held bookings that are still valid, bookings under way and confirmed ones, but not expired holds or finished ones', function () {
        ($this->open)(null, '09:00', '17:00');
        foreach (['held' => '2026-12-01 03:00', 'confirmed' => '2026-12-01 04:00', 'in_progress' => '2026-12-01 05:00'] as $status => $start) {
            $id = ($this->book)($this->tailor->id, $this->package30->id, $status === 'in_progress' ? 'confirmed' : $status, $start, 30);
            if ($status === 'in_progress') {
                DB::table('service_bookings')->where('id', $id)->update(['status' => 'in_progress']);
            }
        }
        $expired = ($this->book)($this->tailor->id, $this->package30->id, 'held', '2026-12-01 06:00', 30);
        DB::table('service_bookings')->where('id', $expired)->update(['hold_expires_at' => now()->subMinute()]);
        foreach (['cancelled', 'completed', 'no_show'] as $i => $end) {
            $id = ($this->book)($this->tailor->id, $this->package30->id, 'confirmed', '2026-12-01 0'.(7 + $i).':00', 30);
            DB::table('service_bookings')->where('id', $id)->update(['status' => $end]);
        }

        $times = ($this->times)(($this->find)());

        foreach (['09:00', '10:00', '11:00'] as $taken) {
            expect($times)->not->toContain($taken);
        }
        foreach (['12:00', '13:00', '14:00', '15:00'] as $free) {   // the expired hold (12:00) and the finished ones (13:00, 14:00, 15:00)
            expect($times)->toContain($free);
        }
    });

    it('leaves a different resource\'s bookings alone, and shows the same time for each free resource', function () {
        ($this->open)($this->tailor, '09:00', '10:00');
        ($this->open)($this->room, '09:00', '10:00');
        ($this->book)($this->room->id, $this->package30->id, 'confirmed', '2026-12-01 03:00', 30);   // the room is busy at 09:00

        $slots = ($this->find)();
        $at0900 = array_values(array_filter($slots, fn (Slot $s) => $s->localStart()->format('H:i') === '09:00'));

        expect($at0900)->toHaveCount(1)->and($at0900[0]->resourceName)->toBe('Rahim (tailor)');
        expect(array_filter($slots, fn (Slot $s) => $s->resourceName === 'Fitting room'))->toBe([]);   // 09:00 is taken, and 09:15 and 09:30 would run into its buffer; nothing else fits before 10:00
    });

    it('can be narrowed to one resource, and finds nothing for one that is unknown, unlinked or out of service', function () {
        ($this->open)($this->tailor, '09:00', '10:00');
        ($this->open)($this->room, '09:00', '10:00');

        expect(array_unique(array_map(fn (Slot $s) => $s->resourceName, ($this->find)('SVC-30', '2026-12-01', null, $this->room->public_id))))->toBe([0 => 'Fitting room']);
        expect(($this->find)('SVC-30', '2026-12-01', null, '01zzzzzzzzzzzzzzzzzzzzzzzz'))->toBe([]);
        expect(($this->find)('SVC-60', '2026-12-01', null, $this->tailor->public_id))->toBe([]);   // the tailor does not serve SVC-60
        app(SaveServiceResource::class)->handle($this->staff, $this->room, 'Fitting room', 'room', false, ['SVC-30', 'SVC-60']);
        expect(($this->find)('SVC-30', '2026-12-01', null, $this->room->public_id))->toBe([]);
    });
});

describe('the clock on the wall and the clock in the database', function () {
    it('works across the Dhaka midnight: a slot at 00:00 on the 3rd is the evening of the 2nd in UTC', function () {
        ($this->open)(null, '00:00', '02:00', 4);   // Thursday 3 Dec, just after midnight
        ($this->book)($this->tailor->id, $this->package30->id, 'confirmed', '2026-12-02 18:45', 30);   // 00:45 Dhaka on the 3rd, kept free until 01:25

        $slots = ($this->find)('SVC-30', '2026-12-03');

        expect($slots[0]->startsAt->format('Y-m-d H:i'))->toBe('2026-12-02 18:00')->and($slots[0]->localDate())->toBe('2026-12-03')->and($slots[0]->localStart()->format('H:i'))->toBe('00:00')
            ->and(($this->times)($slots))->toBe(['00:00', '01:30']);   // 00:00 ends (with its buffer) at 00:40, before the booking; 00:15 to 01:15 run into it
    });

    it('works at the end of the day: 23:30 in Dhaka is 17:30 UTC on the same date', function () {
        ($this->open)(null, '22:00', '23:59', 2);

        $times = ($this->times)(($this->find)('SVC-30', '2026-12-01'));
        $last = array_values(($this->find)('SVC-30', '2026-12-01'));
        $last = end($last);

        expect($times)->toContain('23:15')->and($last->localStart()->format('H:i'))->toBe('23:15')->and($last->startsAt->format('Y-m-d H:i'))->toBe('2026-12-01 17:15');   // 23:15 + 30 minutes ends 23:45; 23:30 would end 00:00
    });

    it('looks for bookings by the real moment, not by the date: a booking just after UTC midnight blocks the right Dhaka day', function () {
        ($this->open)(null, '05:30', '07:00', 2);   // Dhaka 05:30-07:00 on the 1st = UTC 23:30 on the 30th to 01:00 on the 1st
        ($this->book)($this->tailor->id, $this->package30->id, 'confirmed', '2026-12-01 00:15', 30);   // UTC 00:15 = 06:15 Dhaka, kept free until 06:55

        $times = ($this->times)(($this->find)());

        expect($times)->toBe(['05:30']);   // 05:30 ends (with its buffer) at 06:10; every later start runs into the booking
    });
});

describe('asking sensibly', function () {
    it('refuses a range that ends before it starts, a date that is not a date, and more days than allowed', function () {
        $refuses = fn (callable $call, string $reason) => expect($call)->toThrow(BookingException::class, $reason);

        $refuses(fn () => ($this->find)('SVC-30', '2026-12-05', '2026-12-01'), 'range_invalid');
        $refuses(fn () => ($this->find)('SVC-30', '2026-02-30'), 'range_invalid');
        $refuses(fn () => ($this->find)('SVC-30', 'tomorrow'), 'range_invalid');
        $refuses(fn () => ($this->find)('SVC-30', '2026-12-01', '2027-01-15'), 'range_too_long');
        $refuses(fn () => ($this->find)('NOPE'), 'variant_unknown');
        $refuses(fn () => ($this->find)('TEE-1'), 'package_unavailable');
        expect(($this->find)('SVC-30', '2026-12-01', '2026-12-31'))->toBe([]);   // thirty-one days is allowed, and there is nothing open
    });

    it('never returns more than the maximum, earliest first', function () {
        ($this->week)($this->tailor, array_map(fn ($d) => ['weekday' => $d, 'start' => '00:00', 'end' => '23:59'], [1, 2, 3, 4, 5, 6, 7]));
        config(['bookings.max_slots' => 5]);

        $slots = ($this->find)('SVC-30', '2026-12-01', '2026-12-10');

        expect($slots)->toHaveCount(5)->and($slots[0]->startsAt->lessThan($slots[4]->startsAt))->toBeTrue()->and(($this->times)($slots))->toBe(['00:00', '00:15', '00:30', '00:45', '01:00']);
    });

    it('uses the same number of queries for one day or thirty, one resource or two', function () {
        ($this->week)($this->tailor, array_map(fn ($d) => ['weekday' => $d, 'start' => '09:00', 'end' => '17:00'], [1, 2, 3, 4, 5, 6, 7]));
        $count = function (string $to, ?string $resource = null) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->find)('SVC-30', '2026-12-01', $to, $resource);

            return count(DB::getQueryLog());
        };
        ($this->find)('SVC-30', '2026-12-01');   // warm up
        $oneDay = $count('2026-12-01');
        ($this->book)($this->tailor->id, $this->package30->id, 'confirmed', '2026-12-05 04:00', 30);
        ($this->week)($this->room, array_map(fn ($d) => ['weekday' => $d, 'start' => '09:00', 'end' => '17:00'], [1, 2, 3, 4, 5, 6, 7]));

        expect($count('2026-12-31'))->toBe($oneDay)->and($count('2026-12-10', $this->room->public_id))->toBe($oneDay);
    });
});

describe('the search and the database agree', function () {
    it('only offers times the database would accept, and hides exactly the times it would refuse', function () {
        ($this->open)($this->room, '09:00', '13:00');
        ($this->book)($this->room->id, $this->package60->id, 'confirmed', '2026-12-01 05:05', 60);   // 11:05-12:05 in Dhaka, with 5 minutes either side: off the 15-minute grid, so a new slot's OWN buffer decides at the edge
        $package = $this->package60;
        $offered = ($this->times)(($this->find)('SVC-60', '2026-12-01'));
        $try = function (CarbonImmutable $start) use ($package): bool {
            DB::beginTransaction();
            try {
                $end = $start->addMinutes($package->duration_min);
                DB::table('service_bookings')->insert(['public_id' => strtolower((string) Str::ulid()), 'package_id' => $package->id, 'resource_id' => $this->room->id, 'status' => 'held', 'starts_at' => $start, 'ends_at' => $end,
                    'blocks_from' => $start->subMinutes($package->buffer_before_min), 'blocks_until' => $end->addMinutes($package->buffer_after_min), 'hold_expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);

                return true;
            } catch (QueryException) {
                return false;
            } finally {
                DB::rollBack();
            }
        };

        $checked = 0;
        for ($start = CarbonImmutable::parse('2026-12-01 09:00', 'Asia/Dhaka'); $start->addMinutes(60) <= CarbonImmutable::parse('2026-12-01 13:00', 'Asia/Dhaka'); $start = $start->addMinutes(15)) {
            $isOffered = in_array($start->format('H:i'), $offered, true);
            expect($try($start->utc()))->toBe($isOffered, 'at '.$start->format('H:i').' the search said '.($isOffered ? 'free' : 'taken').' but the database disagreed');
            $checked++;
        }

        expect($checked)->toBeGreaterThan(10)->and($offered)->not->toBe([])->and(count($offered))->toBeLessThan($checked);
    });
});
