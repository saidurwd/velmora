<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SaveServicePackage;
use App\Modules\Bookings\Actions\SaveServiceResource;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
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
 * Holding a time from the app under REAL concurrency (Phase 11): every probe is its own PHP process, sending POST /api/v1/services/{sku}/hold through the whole
 * HTTP stack, all released at the same instant, each as a different guest with their own cart. Rule proved: exactly one gets the time, the rest are told it is
 * taken (409 booking.slot_taken), and the database never holds it twice.
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class]);
    $system = Actor::system('t');
    $category = app(SaveCategory::class)->handle($system, null, null, [new TranslationInput('en', 'General')]);
    app(SaveProduct::class)->handle($system, null, new ProductData(ProductType::Service, [new TranslationInput('en', 'Fitting'), new TranslationInput('bn', 'ফিটিং')], categoryIds: [$category->id], defaultVariant: new VariantData('SVC-60')));
    app(SetRegularPrice::class)->handle($system, (int) DB::table('product_variants')->where('sku', 'SVC-60')->value('id'), Money::of(80000));
    app(TransitionProduct::class)->publish($system, Product::query()->firstOrFail());
    app(SaveServiceDefinition::class)->handle($system, 'SVC-60', 'at_provider', 0, 60, 15, true);
    app(SaveServicePackage::class)->handle($system, 'SVC-60', 60, 5, 5, true);
    $this->room = app(SaveServiceResource::class)->handle($system, null, 'Room A', 'room', true, ['SVC-60']);
    app(SetWeeklyAvailability::class)->handle($system, $this->room, array_map(fn ($d) => ['weekday' => $d, 'start' => '09:00', 'end' => '17:00'], [1, 2, 3, 4, 5, 6, 7]));
    $this->start = CarbonImmutable::now('Asia/Dhaka')->addDays(3)->setTime(10, 0)->utc()->format('Y-m-d\TH:i:s\Z');
});

/**
 * @param  list<string>  $tokens  one guest cart token per probe
 * @return list<array{status: int, code: string|null, booking: string|null}>
 */
function raceHolds(array $tokens, string $resource, string $start): array
{
    $startAt = microtime(true) + 4.0 + count($tokens) * 0.2;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (string $token) use ($env, $startAt, $resource, $start) {
        $p = new Process([PHP_BINARY, 'artisan', 'bookings:api-hold-probe', 'SVC-60', $resource, $start, $token, '--start-at='.$startAt], base_path(), $env);
        $p->setTimeout(180)->start();

        return $p;
    }, $tokens);

    return array_map(function (Process $p): array {
        $p->wait();
        $lines = array_values(array_filter(explode("\n", trim($p->getOutput()))));
        $decoded = json_decode((string) end($lines), true);
        expect($decoded)->toBeArray('probe produced no JSON: '.$p->getOutput().$p->getErrorOutput());

        return $decoded;
    }, $processes);
}

it('gives one time to exactly one of twelve visitors who press it at the same instant, and tells the rest it is taken', function () {
    $tokens = array_map(fn (int $i) => bin2hex(random_bytes(20)), range(1, 12));

    $results = raceHolds($tokens, $this->room->public_id, $this->start);

    $won = array_filter($results, fn (array $r) => $r['status'] === 201);
    expect($won)->toHaveCount(1, json_encode($results))
        ->and(array_unique(array_column(array_diff_key($results, $won), 'status')))->toBe([409])
        ->and(array_unique(array_column(array_diff_key($results, $won), 'code')))->toBe(['booking.slot_taken'])
        ->and(DB::table('service_bookings')->where('resource_id', $this->room->id)->where('status', 'held')->count())->toBe(1);
});

it('lets overlapping times be held only in a way that never double-books the room', function () {
    $tokens = array_map(fn (int $i) => bin2hex(random_bytes(20)), range(1, 6));
    $results = [];
    // six different visitors, each pressing a different start 20 minutes apart (each takes an hour plus buffers): most overlap
    $starts = array_map(fn (int $i) => CarbonImmutable::parse($this->start)->addMinutes($i * 20)->format('Y-m-d\TH:i:s\Z'), range(0, 5));
    $startAt = microtime(true) + 6.0;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];
    $processes = array_map(function (string $token, string $start) use ($env, $startAt) {
        $p = new Process([PHP_BINARY, 'artisan', 'bookings:api-hold-probe', 'SVC-60', $this->room->public_id, $start, $token, '--start-at='.$startAt], base_path(), $env);
        $p->setTimeout(180)->start();

        return $p;
    }, $tokens, $starts);
    foreach ($processes as $p) {
        $p->wait();
        $lines = array_values(array_filter(explode("\n", trim($p->getOutput()))));
        $results[] = json_decode((string) end($lines), true);
    }

    $held = DB::table('service_bookings')->where('resource_id', $this->room->id)->where('status', 'held')->orderBy('blocks_from')->get(['blocks_from', 'blocks_until']);
    expect(array_column($results, 'status'))->each->toBeIn([201, 409])->and($held->count())->toBe(count(array_filter($results, fn ($r) => $r['status'] === 201)))->and($held->count())->toBeGreaterThanOrEqual(1);
    for ($i = 1; $i < $held->count(); $i++) {
        expect($held[$i]->blocks_from >= $held[$i - 1]->blocks_until)->toBeTrue();
    }
});
