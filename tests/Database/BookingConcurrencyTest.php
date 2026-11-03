<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SaveServicePackage;
use App\Modules\Bookings\Actions\SaveServiceResource;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Pricing\Services\BreakdownCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * Bookings under REAL concurrency (Phase 8): every probe is its own PHP process with its own MariaDB connection, all released at the same instant. Rule
 * proved: the database never lets one resource be held twice for overlapping time, however many try at once. On CI this runs on MariaDB 11.4.
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class]);
    $system = Actor::system('t');
    $category = app(SaveCategory::class)->handle($system, null, null, [new TranslationInput('en', 'General')]);
    app(SaveProduct::class)->handle($system, null, new ProductData(ProductType::Service, [new TranslationInput('en', 'Fitting'), new TranslationInput('bn', 'ফিটিং')], categoryIds: [$category->id], defaultVariant: new VariantData('SVC-60')));
    app(SaveServiceDefinition::class)->handle($system, 'SVC-60', 'at_provider', 0, 60, 15, true);
    $this->package = app(SaveServicePackage::class)->handle($system, 'SVC-60', 60, 5, 5, true);
    $this->roomA = app(SaveServiceResource::class)->handle($system, null, 'Room A', 'room', true, ['SVC-60']);
    $this->roomB = app(SaveServiceResource::class)->handle($system, null, 'Room B', 'room', true, ['SVC-60']);
});

/**
 * @param  list<array<string, string>>  $jobs  options of one probe each
 * @return list<array<string, mixed>>
 */
function runBookingProbes(array $jobs): array
{
    $startAt = microtime(true) + 4.0 + count($jobs) * 0.2;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (array $options) use ($env, $startAt) {
        $action = $options['action'] ?? (isset($options['hold-ref']) ? 'claim' : (isset($options['sku']) ? 'hold-slot' : 'hold'));
        unset($options['action']);
        $args = [];
        foreach ($options as $name => $value) {
            $args[] = "--{$name}={$value}";
        }
        $p = new Process([PHP_BINARY, 'artisan', 'bookings:probe', $action, ...$args, '--start-at='.$startAt], base_path(), $env);
        $p->setTimeout(180)->start();

        return $p;
    }, $jobs);

    return array_map(function (Process $p): array {
        $p->wait();
        $lines = array_values(array_filter(explode("\n", trim($p->getOutput()))));
        $decoded = json_decode((string) end($lines), true);
        expect($decoded)->toBeArray('probe produced no JSON: '.$p->getOutput().$p->getErrorOutput());

        return $decoded;
    }, $processes);
}

it('holds exactly one of twenty identical bookings for one slot, however many try at the same instant', function () {
    $job = ['resource' => (string) $this->roomA->id, 'package' => (string) $this->package->id, 'start' => '2026-12-01 10:00:00', 'minutes' => '60'];

    $results = runBookingProbes(array_fill(0, 20, $job));

    expect(array_column($results, 'ok'))->each->toBeTrue()
        ->and(count(array_filter(array_column($results, 'held'))))->toBe(1)
        ->and(count(array_filter(array_column($results, 'duplicate'))))->toBe(19)   // the other nineteen were refused by the database's own guard, not by a failure
        ->and(DB::table('service_bookings')->where('resource_id', $this->roomA->id)->count())->toBe(1);
});

it('lets two different rooms each be held once for the same time', function () {
    $jobs = [];
    foreach ([$this->roomA, $this->roomB] as $room) {
        foreach (range(1, 6) as $i) {
            $jobs[] = ['resource' => (string) $room->id, 'package' => (string) $this->package->id, 'start' => '2026-12-02 10:00:00', 'minutes' => '60'];
        }
    }

    $results = runBookingProbes($jobs);

    expect(count(array_filter(array_column($results, 'held'))))->toBe(2)
        ->and(DB::table('service_bookings')->where('resource_id', $this->roomA->id)->count())->toBe(1)->and(DB::table('service_bookings')->where('resource_id', $this->roomB->id)->count())->toBe(1);
});

it('never leaves two overlapping bookings, whatever mix of overlapping times is tried at once', function () {
    $jobs = [];
    foreach (range(0, 23) as $i) {   // a start every 20 minutes for 8 hours, each taking an hour (plus buffers): most of them overlap several others
        $jobs[] = ['resource' => (string) $this->roomA->id, 'package' => (string) $this->package->id, 'start' => (new DateTimeImmutable('2026-12-03 06:00:00'))->modify('+'.($i * 20).' minutes')->format('Y-m-d H:i:s'), 'minutes' => '60'];
    }

    $results = runBookingProbes($jobs);

    $held = DB::table('service_bookings')->where('resource_id', $this->roomA->id)->orderBy('blocks_from')->get(['blocks_from', 'blocks_until']);
    expect(array_column($results, 'ok'))->each->toBeTrue()->and($held->count())->toBe(count(array_filter(array_column($results, 'held'))))->and($held->count())->toBeGreaterThan(1)->and($held->count())->toBeLessThan(24);
    for ($i = 1; $i < $held->count(); $i++) {
        expect($held[$i]->blocks_from >= $held[$i - 1]->blocks_until)->toBeTrue();   // every booking starts after the previous one's blocked time ends
    }
});

it('lets exactly one of twenty different visitors hold the same time through the real hold, each with their own cart', function () {
    $system = Actor::system('t');
    $this->seed(PriceListSeeder::class);
    app(SetRegularPrice::class)->handle($system, (int) DB::table('product_variants')->where('sku', 'SVC-60')->value('id'), Money::of(80000));
    app(SaveServiceDefinition::class)->handle($system, 'SVC-60', 'at_provider', 0, 60, 15, true);
    app(SetWeeklyAvailability::class)->handle($system, $this->roomA, array_map(fn ($d) => ['weekday' => $d, 'start' => '09:00', 'end' => '17:00'], [1, 2, 3, 4, 5, 6, 7]));
    $start = CarbonImmutable::now('Asia/Dhaka')->addDays(3)->setTime(10, 0)->utc()->format('Y-m-d H:i:s');

    $jobs = array_map(fn (int $i) => ['sku' => 'SVC-60', 'resource-public' => $this->roomA->public_id, 'start' => $start, 'token' => str_repeat(dechex($i + 16), 20)], range(1, 20));
    $results = runBookingProbes($jobs);

    expect(array_values(array_filter($results, fn (array $r) => ! $r['ok'])))->toBe([])   // no probe crashed
        ->and(count(array_filter(array_column($results, 'held'))))->toBe(1)
        ->and(array_unique(array_filter(array_column($results, 'reason'))))->each->toBeIn(['slot_taken', 'slot_unavailable'])
        ->and(DB::table('service_bookings')->where('resource_id', $this->roomA->id)->where('status', 'held')->count())->toBe(1)
        ->and(DB::table('cart_items')->whereNotNull('hold_ref')->count())->toBe(1)   // only the winner has the booking in a cart
        ->and(DB::table('booking_events')->where('event', 'held')->count())->toBe(1);
});

it('lets exactly one of several orders claim one held time, however many try at the same instant', function () {
    $variantId = (int) DB::table('product_variants')->where('sku', 'SVC-60')->value('id');
    $order = fn (): int => (int) DB::table('order_items')->where('order_id', app(CreateOrder::class)->handle(new NewOrder(
        Actor::guest('t'), null, 'Rahim', '+8801712345678', null, OrderChannel::Web, 'en', 'online', null,
        (new BreakdownCalculator)->calculate([['variantId' => $variantId, 'quantity' => 1, 'unitMinor' => 35000, 'lineDiscountMinor' => 0, 'taxRateBp' => 0]], false, 0, 0, 0),
        [new NewOrderLine($variantId, 'service', ['en' => 'Fitting', 'bn' => 'ফিটিং'], 'SVC-60')], [],
    ))->id)->value('id');
    [$a, $b] = [$order(), $order()];
    $held = DB::table('service_bookings')->insertGetId([
        'public_id' => $ref = strtolower((string) Str::ulid()), 'package_id' => $this->package->id, 'resource_id' => $this->roomA->id, 'status' => 'held', 'starts_at' => '2026-12-01 04:00:00', 'ends_at' => '2026-12-01 05:00:00',
        'blocks_from' => '2026-12-01 03:55:00', 'blocks_until' => '2026-12-01 05:05:00', 'hold_expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $results = runBookingProbes(array_map(fn (int $i) => ['hold-ref' => $ref, 'variant' => (string) $variantId, 'order-item' => (string) ($i % 2 === 0 ? $a : $b)], range(0, 7)));

    expect(array_values(array_filter($results, fn (array $r) => ! $r['ok'])))->toBe([])
        ->and(count(array_filter(array_column($results, 'claimed'))))->toBe(1)
        ->and(array_unique(array_filter(array_column($results, 'reason'))))->toBe(['hold_lost'])
        ->and(DB::table('service_bookings')->where('id', $held)->whereIn('order_item_id', [$a, $b])->count())->toBe(1)
        ->and(DB::table('booking_events')->where('booking_id', $held)->where('event', 'ordered')->count())->toBe(1);
});

/** @return array{0: int, 1: string} an order line with a confirmed booking on the Rooms A at the given start: its order item id and the booking's public id */
function confirmedServiceBooking(object $t, string $start, int $variantId, int $resourceId): array
{
    $orderId = app(CreateOrder::class)->handle(new NewOrder(
        Actor::guest('t'), null, 'Rahim', '+8801712345678', null, OrderChannel::Web, 'en', 'online', null,
        (new BreakdownCalculator)->calculate([['variantId' => $variantId, 'quantity' => 1, 'unitMinor' => 35000, 'lineDiscountMinor' => 0, 'taxRateBp' => 0]], false, 0, 0, 0),
        [new NewOrderLine($variantId, 'service', ['en' => 'Fitting', 'bn' => 'ফিটিং'], 'SVC-60')], [],
    ))->id;
    $itemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');
    $from = CarbonImmutable::parse($start, 'UTC');
    $id = DB::table('service_bookings')->insertGetId([
        'public_id' => $ref = strtolower((string) Str::ulid()), 'order_item_id' => $itemId, 'package_id' => $t->package->id, 'resource_id' => $resourceId, 'status' => 'confirmed', 'starts_at' => $from, 'ends_at' => $from->addHour(),
        'blocks_from' => $from->subMinutes(5), 'blocks_until' => $from->addMinutes(65), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$itemId, $ref];
}

it('ends a booking once when eight people cancel it at the same instant', function () {
    $variantId = (int) DB::table('product_variants')->where('sku', 'SVC-60')->value('id');
    [, $ref] = confirmedServiceBooking($this, CarbonImmutable::now()->addDays(3)->setTime(5, 0)->format('Y-m-d H:i:s'), $variantId, $this->roomA->id);

    $results = runBookingProbes(array_fill(0, 8, ['action' => 'cancel', 'booking' => $ref]));

    expect(array_values(array_filter($results, fn (array $r) => ! $r['ok'])))->toBe([])
        ->and(count(array_filter(array_column($results, 'done'))))->toBe(1)
        ->and(array_unique(array_filter(array_column($results, 'reason'))))->toBe(['not_cancellable'])
        ->and(DB::table('booking_cancellations')->count())->toBe(1)
        ->and(DB::table('booking_events')->where('event', 'cancelled')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('event_name', 'bookings.ended')->count())->toBe(1)
        ->and(DB::table('service_bookings')->where('public_id', $ref)->value('status'))->toBe('cancelled');
});

it('lets exactly one of two bookings move onto the same free time, however many try at once', function () {
    $variantId = (int) DB::table('product_variants')->where('sku', 'SVC-60')->value('id');
    $day = CarbonImmutable::now()->addDays(3);
    [, $a] = confirmedServiceBooking($this, $day->setTime(5, 0)->format('Y-m-d H:i:s'), $variantId, $this->roomA->id);
    [, $b] = confirmedServiceBooking($this, $day->setTime(8, 0)->format('Y-m-d H:i:s'), $variantId, $this->roomA->id);
    $target = $day->setTime(6, 30)->format('Y-m-d H:i:s');   // between them: only one of them can fit there
    app(SetWeeklyAvailability::class)->handle(Actor::system('t'), $this->roomA, array_map(fn ($d) => ['weekday' => $d, 'start' => '00:00', 'end' => '23:59'], [1, 2, 3, 4, 5, 6, 7]));

    $results = runBookingProbes(array_map(fn (int $i) => ['action' => 'reschedule', 'booking' => $i % 2 === 0 ? $a : $b, 'start' => $target], range(0, 5)));

    expect(array_values(array_filter($results, fn (array $r) => ! $r['ok'])))->toBe([])
        ->and(count(array_filter(array_column($results, 'done'))))->toBe(1)
        ->and(array_unique(array_filter(array_column($results, 'reason'))))->each->toBeIn(['slot_unavailable', 'slot_taken', 'same_time'])   // same_time: the winner's own repeats
        ->and(DB::table('service_bookings')->where('resource_id', $this->roomA->id)->where('starts_at', $target)->count())->toBe(1)
        ->and(DB::table('booking_events')->where('event', 'rescheduled')->count())->toBe(1);
});

it('sends a reminder once when six scheduler runs start at the same instant', function () {
    $variantId = (int) DB::table('product_variants')->where('sku', 'SVC-60')->value('id');
    [, $ref] = confirmedServiceBooking($this, CarbonImmutable::now()->addHours(5)->format('Y-m-d H:i:s'), $variantId, $this->roomA->id);

    $results = runBookingProbes(array_fill(0, 6, ['action' => 'remind']));

    expect(array_values(array_filter($results, fn (array $r) => ! $r['ok'])))->toBe([])
        ->and(array_sum(array_column($results, 'sent')))->toBe(1)   // the one text message (this order has no email address)
        ->and(DB::table('booking_notices')->count())->toBe(1)
        ->and(DB::table('booking_notices')->value('status'))->toBe('sent')
        ->and((int) DB::table('booking_notices')->value('attempts'))->toBe(1)
        ->and(DB::table('service_bookings')->where('public_id', $ref)->value('status'))->toBe('confirmed');
});
