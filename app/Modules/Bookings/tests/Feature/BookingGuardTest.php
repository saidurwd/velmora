<?php

declare(strict_types=1);

use App\Modules\Bookings\Enums\BookingStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    bookingsWorld($this);
    $this->at = fn (string $time, string $day = '2026-12-01') => "{$day} {$time}";
    $this->fails = fn (callable $call) => expect($call)->toThrow(QueryException::class);
});

describe('the database refuses a double booking', function () {
    it('accepts bookings that only touch, on one resource', function () {
        ($this->book)($this->room->id, $this->package60->id, 'confirmed', ($this->at)('10:00'), 60);   // blocked 09:55 - 11:05
        $second = ($this->book)($this->room->id, $this->package60->id, 'confirmed', ($this->at)('11:10'), 60);   // blocked 11:05 - 12:15: touches, does not overlap

        expect($second)->toBeInt();
    });

    it('refuses a booking that overlaps one that is held or confirmed, whichever way round', function () {
        ($this->book)($this->room->id, $this->package60->id, 'confirmed', ($this->at)('10:00'), 60);

        ($this->fails)(fn () => ($this->book)($this->room->id, $this->package60->id, 'held', ($this->at)('10:30'), 60));
        ($this->fails)(fn () => ($this->book)($this->room->id, $this->package60->id, 'confirmed', ($this->at)('10:15'), 60));
        ($this->fails)(fn () => ($this->book)($this->room->id, $this->package30->id, 'held', ($this->at)('10:20'), 30));   // inside the other
        ($this->fails)(fn () => ($this->book)($this->room->id, $this->package60->id, 'held', ($this->at)('09:00'), 90));   // around the other's start
        ($this->book)($this->room->id, $this->package60->id, 'held', ($this->at)('12:00'), 60);   // clear of it
        expect(DB::table('service_bookings')->count())->toBe(2);
    });

    it('lets different resources be booked at the same time', function () {
        ($this->book)($this->room->id, $this->package60->id, 'confirmed', ($this->at)('10:00'), 60);

        expect(($this->book)($this->tailor->id, $this->package30->id, 'confirmed', ($this->at)('10:00'), 30))->toBeInt();
    });

    it('counts a held slot like a confirmed one, and gives the time back when a booking is cancelled, completed or does not show up', function () {
        ($this->book)($this->room->id, $this->package60->id, 'held', ($this->at)('10:00'), 60);
        ($this->fails)(fn () => ($this->book)($this->room->id, $this->package60->id, 'confirmed', ($this->at)('10:30'), 60));

        foreach (['cancelled' => ['held'], 'no_show' => ['confirmed'], 'completed' => ['confirmed']] as $end => [$start]) {
            $day = ($this->at)('10:00', '2026-12-0'.(2 + array_search($end, ['cancelled', 'no_show', 'completed'], true)));
            $id = ($this->book)($this->room->id, $this->package60->id, $start, $day, 60);
            ($this->fails)(fn () => ($this->book)($this->room->id, $this->package60->id, 'held', $day, 60));
            DB::table('service_bookings')->where('id', $id)->update(['status' => $end]);
            expect(($this->book)($this->room->id, $this->package60->id, 'held', $day, 60))->toBeInt();   // the time is free again
        }
    });

    it('keeps a booking that is under way in the way of anyone else, until it is finished', function () {
        $id = ($this->book)($this->room->id, $this->package60->id, 'confirmed', ($this->at)('10:00'), 60);
        DB::table('service_bookings')->where('id', $id)->update(['status' => 'in_progress']);

        ($this->fails)(fn () => ($this->book)($this->room->id, $this->package60->id, 'held', ($this->at)('10:30'), 60));
        DB::table('service_bookings')->where('id', $id)->update(['status' => 'completed']);
        expect(($this->book)($this->room->id, $this->package60->id, 'held', ($this->at)('10:30'), 60))->toBeInt();
    });

    it('counts the buffers as taken time, so a booking cannot start inside the buffer of the one before', function () {
        ($this->book)($this->room->id, $this->package30->id, 'confirmed', ($this->at)('10:00'), 30);   // service until 10:30, kept free until 10:40

        ($this->fails)(fn () => ($this->book)($this->room->id, $this->package30->id, 'held', ($this->at)('10:35'), 30));
        expect(($this->book)($this->room->id, $this->package30->id, 'held', ($this->at)('10:40'), 30))->toBeInt();
        ($this->fails)(fn () => ($this->book)($this->room->id, $this->package60->id, 'held', ($this->at)('10:42'), 60));   // its own 5 minutes before reaches into the buffer
    });

    it('lets a booking move over its own old time, but never onto somebody else\'s', function () {
        $mine = ($this->book)($this->room->id, $this->package60->id, 'confirmed', ($this->at)('10:00'), 60);
        ($this->book)($this->room->id, $this->package60->id, 'confirmed', ($this->at)('12:30'), 60);   // blocked 12:25 - 13:35
        $move = fn (string $start, string $end, string $from, string $until) => DB::table('service_bookings')->where('id', $mine)->update(['starts_at' => ($this->at)($start), 'ends_at' => ($this->at)($end), 'blocks_from' => ($this->at)($from), 'blocks_until' => ($this->at)($until)]);

        expect($move('10:30', '11:30', '10:25', '11:35'))->toBe(1);   // shifted by half an hour, still overlapping where it was
        ($this->fails)(fn () => $move('12:00', '13:00', '11:55', '13:05'));   // into the next booking
    });
});

describe('the other rules of the table', function () {
    it('refuses a booking that ends before it starts, whose blocked time is smaller than the service, or a hold with no expiry', function () {
        $row = fn (array $over) => array_replace(['public_id' => strtolower((string) Str::ulid()), 'package_id' => $this->package60->id, 'resource_id' => $this->room->id, 'status' => 'confirmed', 'starts_at' => '2026-12-05 10:00:00', 'ends_at' => '2026-12-05 11:00:00', 'blocks_from' => '2026-12-05 10:00:00', 'blocks_until' => '2026-12-05 11:00:00', 'hold_expires_at' => null, 'created_at' => now(), 'updated_at' => now()], $over);

        ($this->fails)(fn () => DB::table('service_bookings')->insert($row(['ends_at' => '2026-12-05 09:00:00'])));
        ($this->fails)(fn () => DB::table('service_bookings')->insert($row(['blocks_until' => '2026-12-05 10:30:00'])));
        ($this->fails)(fn () => DB::table('service_bookings')->insert($row(['blocks_from' => '2026-12-05 10:30:00'])));
        ($this->fails)(fn () => DB::table('service_bookings')->insert($row(['status' => 'held'])));
        ($this->fails)(fn () => DB::table('service_bookings')->insert($row(['status' => 'maybe'])));
        expect(DB::table('service_bookings')->insert($row(['status' => 'held', 'hold_expires_at' => '2026-12-05 09:00:00'])))->toBeTrue();
    });

    it('allows exactly the status moves the enum allows, and no others', function () {
        $n = 0;
        foreach (BookingStatus::cases() as $from) {
            foreach (BookingStatus::cases() as $to) {
                $id = ($this->book)($this->room->id, $this->package30->id, $from->value, '2027-01-'.sprintf('%02d', 1 + intdiv($n, 24)).' '.sprintf('%02d', $n % 24).':00', 30);
                $n++;
                $moved = fn () => DB::table('service_bookings')->where('id', $id)->update(['status' => $to->value]);

                if ($from->canMoveTo($to)) {
                    expect($moved())->toBe(1);
                } elseif ($from === $to) {
                    expect($moved())->toBe(0);   // writing the same status again changes nothing, so no row is changed
                } else {
                    ($this->fails)($moved);
                }
            }
        }
    });

    it('keeps the history of a booking: an event can be neither changed nor removed', function () {
        $id = ($this->book)($this->room->id, $this->package30->id, 'confirmed', ($this->at)('10:00'), 30);
        $event = DB::table('booking_events')->insertGetId(['booking_id' => $id, 'event' => 'confirmed', 'actor_type' => 'staff', 'actor_id' => null, 'note' => null, 'created_at' => now()]);

        ($this->fails)(fn () => DB::table('booking_events')->where('id', $event)->update(['note' => 'edited']));
        ($this->fails)(fn () => DB::table('booking_events')->where('id', $event)->delete());
        ($this->fails)(fn () => DB::table('service_bookings')->where('id', $id)->delete());   // a booking with a history is never removed
    });

    it('is a booking in the order\'s own table only through the order line, once', function () {
        $id = ($this->book)($this->room->id, $this->package30->id, 'confirmed', ($this->at)('10:00'), 30);

        ($this->fails)(fn () => DB::table('service_bookings')->where('id', $id)->update(['order_item_id' => 999999]));   // there is no such order line
    });
});

describe('services stay out of stock and parcels', function () {
    it('never mentions stock or shipping in the Bookings module', function () {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Bookings'), FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($files as $file) {
            if (! in_array($file->getExtension(), ['php'], true) || str_contains($file->getPathname(), '/tests/')) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/stock_|StockReservations|Inventory\\\\|Shipping\\\\|shipments/i', $code) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        expect($offenders)->toBe([]);
    });
});
