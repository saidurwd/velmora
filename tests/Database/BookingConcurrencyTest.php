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
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
        $args = [];
        foreach ($options as $name => $value) {
            $args[] = "--{$name}={$value}";
        }
        $action = isset($options['sku']) ? 'hold-slot' : 'hold';
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
