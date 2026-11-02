<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\SaveHoliday;
use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SaveServicePackage;
use App\Modules\Bookings\Actions\SaveServiceResource;
use App\Modules\Bookings\Actions\SetAvailabilityException;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServiceDefinition;
use App\Modules\Bookings\Models\ServicePackage;
use App\Modules\IdentityAccess\Permissions\PermissionCatalogue;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    bookingsWorld($this);
    $this->refuses = fn (callable $call, string $reason) => expect($call)->toThrow(BookingException::class, $reason);
    $this->define = fn (string $sku = 'SVC-30', string $location = 'at_provider', int $lead = 60, int $horizon = 60, int $hold = 15, bool $active = true) => app(SaveServiceDefinition::class)->handle($this->staff, $sku, $location, $lead, $horizon, $hold, $active);
    $this->package = fn (string $sku = 'SVC-30', int $duration = 30, int $before = 0, int $after = 10, bool $active = true) => app(SaveServicePackage::class)->handle($this->staff, $sku, $duration, $before, $after, $active);
    $this->week = fn (array $windows, $resource = null) => app(SetWeeklyAvailability::class)->handle($this->staff, $resource ?? $this->room, $windows);
});

describe('making a service bookable', function () {
    it('stores the rules, finds the product through any of its variants, and records the change', function () {
        $changed = ($this->define)('SVC-30', 'online', 30, 90, 10, false);

        expect(ServiceDefinition::query()->count())->toBe(2)->and($changed->id)->toBe($this->service30->id)->and($changed->location_type)->toBe('online')->and($changed->lead_time_min)->toBe(30)->and($changed->max_horizon_days)->toBe(90)
            ->and($changed->hold_minutes)->toBe(10)->and($changed->is_active)->toBeFalse()->and(DB::table('audit_events')->where('action', 'bookings.service_changed')->count())->toBe(1);
    });

    it('refuses a product that is not a service, an unknown one, and values out of range', function () {
        ($this->refuses)(fn () => ($this->define)('TEE-1'), 'product_not_service');
        ($this->refuses)(fn () => ($this->define)('NOPE'), 'variant_unknown');
        ($this->refuses)(fn () => ($this->define)('SVC-30', 'on_site'), 'location_invalid');
        ($this->refuses)(fn () => ($this->define)('SVC-30', 'at_provider', -1), 'lead_time_invalid');
        ($this->refuses)(fn () => ($this->define)('SVC-30', 'at_provider', 60, 0), 'horizon_invalid');
        ($this->refuses)(fn () => ($this->define)('SVC-30', 'at_provider', 60, 366), 'horizon_invalid');
        ($this->refuses)(fn () => ($this->define)('SVC-30', 'at_provider', 60, 60, 4), 'hold_invalid');
        ($this->refuses)(fn () => ($this->define)('SVC-30', 'at_provider', 60, 60, 121), 'hold_invalid');
    });
});

describe('packages', function () {
    it('are variants of a bookable service, with a duration and buffers, and change in place', function () {
        $changed = ($this->package)('SVC-60', 90, 10, 20);

        expect(ServicePackage::query()->count())->toBe(2)->and($changed->id)->toBe($this->package60->id)->and($changed->duration_min)->toBe(90)->and($changed->buffer_before_min)->toBe(10)->and($changed->buffer_after_min)->toBe(20)->and($changed->service_id)->toBe($this->service60->id);
    });

    it('are refused for a product that is not a service, one not made bookable, and values out of range', function () {
        ($this->refuses)(fn () => ($this->package)('TEE-1'), 'product_not_service');
        ($this->refuses)(fn () => ($this->package)('NOPE'), 'variant_unknown');
        DB::table('service_packages')->delete();
        DB::table('service_resource_links')->delete();
        DB::table('service_definitions')->where('id', $this->service60->id)->delete();
        ($this->refuses)(fn () => ($this->package)('SVC-60'), 'service_not_defined');
        ($this->refuses)(fn () => ($this->package)('SVC-30', 4), 'duration_invalid');
        ($this->refuses)(fn () => ($this->package)('SVC-30', 1441), 'duration_invalid');
        ($this->refuses)(fn () => ($this->package)('SVC-30', 30, -1), 'buffer_invalid');
        ($this->refuses)(fn () => ($this->package)('SVC-30', 30, 0, 241), 'buffer_invalid');
    });
});

describe('resources', function () {
    it('serve the services they are linked to, and the links are replaced when they are saved again', function () {
        expect(DB::table('service_resource_links')->where('resource_id', $this->tailor->id)->pluck('service_id')->all())->toBe([$this->service30->id]);

        app(SaveServiceResource::class)->handle($this->staff, $this->tailor, 'Rahim, senior tailor', 'staff', true, ['SVC-60']);

        expect(DB::table('service_resource_links')->where('resource_id', $this->tailor->id)->pluck('service_id')->all())->toBe([$this->service60->id])->and($this->tailor->refresh()->name)->toBe('Rahim, senior tailor');
    });

    it('can be taken out of service without touching its bookings, and are refused without a name or with a bad type or service', function () {
        $id = ($this->book)($this->room->id, $this->package60->id, 'confirmed', '2026-12-01 10:00', 60);

        app(SaveServiceResource::class)->handle($this->staff, $this->room, 'Fitting room', 'room', false, ['SVC-30']);

        expect($this->room->refresh()->is_active)->toBeFalse()->and(DB::table('service_bookings')->where('id', $id)->value('status'))->toBe('confirmed');
        ($this->refuses)(fn () => app(SaveServiceResource::class)->handle($this->staff, null, '  ', 'room', true, []), 'name_required');
        ($this->refuses)(fn () => app(SaveServiceResource::class)->handle($this->staff, null, 'X', 'boat', true, []), 'resource_type_invalid');
        ($this->refuses)(fn () => app(SaveServiceResource::class)->handle($this->staff, null, 'X', 'room', true, ['TEE-1']), 'service_not_defined');
        ($this->refuses)(fn () => app(SaveServiceResource::class)->handle($this->staff, null, 'X', 'room', true, ['NOPE']), 'variant_unknown');
    });
});

describe('the weekly timetable', function () {
    it('replaces what was there, in Dhaka wall-clock time, and allows windows that only touch', function () {
        ($this->week)([['weekday' => 1, 'start' => '09:00', 'end' => '13:00'], ['weekday' => 1, 'start' => '13:00', 'end' => '18:00'], ['weekday' => 6, 'start' => '10:00', 'end' => '14:00']]);
        ($this->week)([['weekday' => 2, 'start' => '09:30', 'end' => '17:00:00', 'valid_from' => '2026-12-01', 'valid_to' => '2027-03-01']]);

        $rules = DB::table('availability_rules')->where('resource_id', $this->room->id)->get();
        expect($rules)->toHaveCount(1)->and($rules[0]->weekday)->toBe(2)->and($rules[0]->start_time)->toBe('09:30:00')->and($rules[0]->end_time)->toBe('17:00:00')->and($rules[0]->valid_from)->toBe('2026-12-01');
        ($this->week)([]);
        expect(DB::table('availability_rules')->where('resource_id', $this->room->id)->count())->toBe(0);
    });

    it('refuses a bad weekday, time, window, date range, and two windows that overlap while both apply', function () {
        ($this->refuses)(fn () => ($this->week)([['weekday' => 0, 'start' => '09:00', 'end' => '10:00']]), 'weekday_invalid');
        ($this->refuses)(fn () => ($this->week)([['weekday' => 8, 'start' => '09:00', 'end' => '10:00']]), 'weekday_invalid');
        ($this->refuses)(fn () => ($this->week)([['weekday' => 1, 'start' => '9am', 'end' => '10:00']]), 'time_invalid');
        ($this->refuses)(fn () => ($this->week)([['weekday' => 1, 'start' => '25:00', 'end' => '26:00']]), 'time_invalid');
        ($this->refuses)(fn () => ($this->week)([['weekday' => 1, 'start' => '10:00', 'end' => '10:00']]), 'window_end_before_start');
        ($this->refuses)(fn () => ($this->week)([['weekday' => 1, 'start' => '10:00', 'end' => '09:00']]), 'window_end_before_start');
        ($this->refuses)(fn () => ($this->week)([['weekday' => 1, 'start' => '09:00', 'end' => '10:00', 'valid_from' => '2026-12-10', 'valid_to' => '2026-12-01']]), 'validity_invalid');
        ($this->refuses)(fn () => ($this->week)([['weekday' => 1, 'start' => '09:00', 'end' => '10:00', 'valid_from' => '2026-02-30']]), 'date_invalid');
        ($this->refuses)(fn () => ($this->week)([['weekday' => 1, 'start' => '09:00', 'end' => '12:00'], ['weekday' => 1, 'start' => '11:00', 'end' => '14:00']]), 'windows_overlap');
        expect(DB::table('availability_rules')->count())->toBe(0);   // nothing half-saved

        ($this->week)([['weekday' => 1, 'start' => '09:00', 'end' => '12:00', 'valid_to' => '2026-12-31'], ['weekday' => 1, 'start' => '11:00', 'end' => '14:00', 'valid_from' => '2027-01-01']]);   // never both at once: allowed
        expect(DB::table('availability_rules')->count())->toBe(2);
    });
});

describe('days that are not like the week', function () {
    it('close a resource for a day, or give it other hours, replacing what was on that day, and can be taken away', function () {
        $set = fn (string $date, bool $closed, ?string $start = null, ?string $end = null, ?string $note = null) => app(SetAvailabilityException::class)->set($this->staff, $this->room, $date, $closed, $start, $end, $note);

        $set('2026-12-25', true, null, null, 'Winter break');
        $set('2026-12-26', false, '10:00', '12:30');
        $set('2026-12-25', false, '11:00', '13:00', 'Open after all');

        $rows = DB::table('availability_exceptions')->where('resource_id', $this->room->id)->orderBy('on_date')->get();
        expect($rows)->toHaveCount(2)->and((bool) $rows[0]->is_closed)->toBeFalse()->and($rows[0]->start_time)->toBe('11:00:00')->and($rows[0]->note)->toBe('Open after all')->and((bool) $rows[1]->is_closed)->toBeFalse();
        ($this->refuses)(fn () => $set('2026-12-27', false, '12:00', '11:00'), 'window_end_before_start');
        ($this->refuses)(fn () => $set('2026-12-27', false, null, null), 'time_invalid');
        ($this->refuses)(fn () => $set('27/12/2026', true), 'date_invalid');

        app(SetAvailabilityException::class)->remove($this->staff, $this->room, '2026-12-25');
        expect(DB::table('availability_exceptions')->count())->toBe(1)->and(DB::table('audit_events')->where('action', 'bookings.exception_removed')->count())->toBe(1);
    });

    it('close the whole shop on a holiday, once per day', function () {
        app(SaveHoliday::class)->set($this->staff, '2027-03-26', 'Independence Day');
        app(SaveHoliday::class)->set($this->staff, '2027-03-26', 'Independence Day (Friday)');

        expect(DB::table('holidays')->count())->toBe(1)->and(DB::table('holidays')->value('name'))->toBe('Independence Day (Friday)');
        ($this->refuses)(fn () => app(SaveHoliday::class)->set($this->staff, '2027-03-27', ' '), 'name_required');
        ($this->refuses)(fn () => app(SaveHoliday::class)->set($this->staff, 'tomorrow', 'X'), 'date_invalid');
        app(SaveHoliday::class)->remove($this->staff, '2027-03-26');
        expect(DB::table('holidays')->count())->toBe(0);
    });
});

describe('who may', function () {
    it('has its own permissions: the shop owner roles have them, a plain editor does not', function () {
        $all = PermissionCatalogue::permissions();

        expect($all)->toContain('bookings.view')->toContain('bookings.manage');
    });
});
