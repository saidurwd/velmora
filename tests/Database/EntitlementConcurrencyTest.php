<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\DigitalProducts\Actions\AllocateLicenseKeys;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Pricing\Services\BreakdownCalculator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * The right to download under REAL concurrency (Phase 8): every probe is its own PHP process with its own MariaDB connection, all released at the same
 * instant. Rule proved: however many grants run at once for one paid order, each digital line gets exactly one right.
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class]);
    $system = Actor::system('t');
    $category = app(SaveCategory::class)->handle($system, null, null, [new TranslationInput('en', 'General')]);
    $make = fn (string $sku): int => (int) app(SaveProduct::class)->handle($system, null, new ProductData(ProductType::Digital, [new TranslationInput('en', $sku), new TranslationInput('bn', $sku)], categoryIds: [$category->id], defaultVariant: new VariantData($sku)))->variants()->value('id');
    $this->variants = [$make('BOOK-1'), $make('BOOK-2'), $make('BOOK-3')];

    /** A paid order of the three books. Returns its id. */
    $this->paidOrder = function () use ($system): int {
        $calc = array_map(fn ($v) => ['variantId' => $v, 'quantity' => 1, 'unitMinor' => 35000, 'lineDiscountMinor' => 0, 'taxRateBp' => 0], $this->variants);
        $lines = array_map(fn ($v) => new NewOrderLine($v, 'digital', ['en' => 'Book', 'bn' => 'বই'], 'SKU-'.$v), $this->variants);
        $order = app(CreateOrder::class)->handle(new NewOrder(Actor::guest('t'), null, 'Rahim', '+8801712345678', null, OrderChannel::Web, 'en', 'online', null, (new BreakdownCalculator)->calculate($calc, false, 0, 0, 0), $lines, []));
        app(TransitionOrder::class)->status($system, $order->id, OrderStatus::Confirmed, 'test');
        app(TransitionOrder::class)->payment($system, $order->id, PaymentStatus::Paid, 'test');

        return $order->id;
    };
});

/**
 * @param  list<array{0: string, 1: list<string>}>  $jobs  [artisan command, arguments]
 * @return list<array<string, mixed>>
 */
function runDigitalProbes(array $jobs): array
{
    $startAt = microtime(true) + 4.0 + count($jobs) * 0.2;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (array $job) use ($env, $startAt) {
        [$command, $args] = $job;
        $p = new Process([PHP_BINARY, 'artisan', $command, ...$args, '--start-at='.$startAt], base_path(), $env);
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

it('makes exactly one right per digital line when six grants for the same paid order run at the same instant', function () {
    foreach (range(1, 3) as $round) {
        $orderId = ($this->paidOrder)();
        DB::table('entitlements')->where('order_id', $orderId)->delete();   // paying already granted them through the normal event; start the race from none
        $audited = DB::table('audit_events')->where('action', 'digital.entitlement_granted')->count();

        $results = runDigitalProbes(array_fill(0, 6, ['digital:probe', ['grant', "--order={$orderId}"]]));

        expect(array_column($results, 'ok'))->each->toBeTrue()
            ->and(array_sum(array_column($results, 'made')))->toBe(3)   // together they made the three rights, once each
            ->and(DB::table('entitlements')->where('order_id', $orderId)->count())->toBe(3)
            ->and(DB::table('entitlements')->where('order_id', $orderId)->distinct()->count('order_item_id'))->toBe(3)
            ->and(DB::table('audit_events')->where('action', 'digital.entitlement_granted')->count() - $audited)->toBe(3);   // and each was recorded once
    }
});

it('makes nothing for an order that is not paid, even when many ask at once', function () {
    $orderId = ($this->paidOrder)();
    DB::table('entitlements')->delete();
    DB::table('orders')->where('id', $orderId)->update(['payment_status' => 'unpaid']);   // simulate an order whose payment is not complete

    $results = runDigitalProbes(array_fill(0, 4, ['digital:probe', ['grant', "--order={$orderId}"]]));

    expect(array_sum(array_column($results, 'made')))->toBe(0)->and(DB::table('entitlements')->count())->toBe(0);
});

it('never passes the download limit when ten people press download at the same instant: exactly the limit is granted, the rest are refused', function () {
    config(['digital.disk' => 'local']);
    $orderId = ($this->paidOrder)();
    $right = DB::table('entitlements')->where('order_id', $orderId)->orderBy('id')->first();
    DB::table('entitlements')->where('id', $right->id)->update(['download_limit' => 3]);
    $asset = DB::table('digital_assets')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'variant_id' => $right->variant_id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('digital_asset_versions')->insert(['asset_id' => $asset, 'version' => 1, 'storage_key' => 'digital/test/'.bin2hex(random_bytes(6)), 'original_filename' => 'a.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'created_at' => now()]);

    $results = runDigitalProbes(array_fill(0, 10, ['digital:probe', ['download', "--entitlement={$right->public_id}"]]));

    expect(array_column($results, 'ok'))->each->toBeTrue()
        ->and(count(array_filter(array_column($results, 'granted'))))->toBe(3)
        ->and(array_count_values(array_filter(array_column($results, 'reason'))))->toBe(['download_limit_reached' => 7])
        ->and((int) DB::table('entitlements')->where('id', $right->id)->value('downloads_used'))->toBe(3)
        ->and(DB::table('download_logs')->where('entitlement_id', $right->id)->where('outcome', 'granted')->count())->toBe(3)
        ->and(DB::table('download_logs')->where('entitlement_id', $right->id)->where('outcome', 'limit_reached')->count())->toBe(7);
});

/** A paid order of one digital line (the first book), for a pool that already exists. Returns the right's public id. */
function paidBookOrder(object $t): string
{
    $system = Actor::system('t');
    $order = app(CreateOrder::class)->handle(new NewOrder(Actor::guest('t'), null, 'Rahim', '+8801712345678', null, OrderChannel::Web, 'en', 'online', null, (new BreakdownCalculator)->calculate([['variantId' => $t->variants[0], 'quantity' => 1, 'unitMinor' => 35000, 'lineDiscountMinor' => 0, 'taxRateBp' => 0]], false, 0, 0, 0), [new NewOrderLine($t->variants[0], 'digital', ['en' => 'Book', 'bn' => 'বই'], 'SKU-1')], []));
    app(TransitionOrder::class)->status($system, $order->id, OrderStatus::Confirmed, 'test');
    app(TransitionOrder::class)->payment($system, $order->id, PaymentStatus::Paid, 'test');

    return (string) DB::table('entitlements')->where('order_id', $order->id)->value('public_id');
}

it('gives each licence key to one customer only when twenty customers are given keys at the same instant from a pool of twelve', function () {
    $pool = DB::table('license_key_pools')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'variant_id' => $this->variants[0], 'created_at' => now(), 'updated_at' => now()]);
    $rights = array_map(fn () => paidBookOrder($this), range(1, 20));   // the pool is empty, so all twenty are owed one key and wait
    foreach (range(1, 12) as $i) {
        DB::table('license_keys')->insert(['pool_id' => $pool, 'key_encrypted' => Crypt::encryptString("KEY-{$i}"), 'fingerprint' => hash('sha256', "KEY-{$i}"), 'status' => 'available', 'created_at' => now()]);
    }
    expect(DB::table('license_keys')->where('status', 'allocated')->count())->toBe(0);

    $results = runDigitalProbes(array_map(fn (string $id) => ['digital:probe', ['allocate', "--entitlement={$id}"]], $rights));

    $holders = DB::table('license_keys')->where('status', 'allocated')->pluck('entitlement_id');
    expect(array_column($results, 'ok'))->each->toBeTrue()
        ->and(array_sum(array_column($results, 'given')))->toBe(12)
        ->and($holders)->toHaveCount(12)->and($holders->unique())->toHaveCount(12)   // twelve different customers got one key each; no key went twice, no customer got two
        ->and(DB::table('license_keys')->where('status', 'available')->count())->toBe(0)
        ->and(DB::table('license_keys')->whereNotNull('entitlement_id')->distinct()->count('id'))->toBe(12);
});

it('loses and doubles no key when files of keys are imported while customers are being given keys', function () {
    DB::table('license_key_pools')->insert(['public_id' => strtolower((string) Str::ulid()), 'variant_id' => $this->variants[0], 'created_at' => now(), 'updated_at' => now()]);
    $rights = array_map(fn () => paidBookOrder($this), range(1, 10));
    $files = [];
    foreach ([[1, 4], [3, 6], [5, 8]] as $n => [$from, $to]) {   // three files with overlapping keys: eight different keys in all
        $files[$n] = tempnam(sys_get_temp_dir(), 'keys');
        file_put_contents($files[$n], implode("\n", array_map(fn ($i) => "KEY-{$i}", range($from, $to))));
    }

    $jobs = [];
    foreach ($files as $file) {
        $jobs[] = ['digital:probe', ['import', '--sku=BOOK-1', "--file={$file}"]];
    }
    foreach (array_slice($rights, 0, 6) as $id) {
        $jobs[] = ['digital:probe', ['allocate', "--entitlement={$id}"]];
    }
    $results = runDigitalProbes($jobs);
    foreach ($files as $file) {
        unlink($file);
    }
    app(AllocateLicenseKeys::class)->fillWaiting();   // what the next scheduled check would do, so nothing is left owed that could be given

    $allocated = DB::table('license_keys')->where('status', 'allocated')->pluck('entitlement_id');
    expect(array_column($results, 'ok'))->each->toBeTrue()
        ->and(DB::table('license_keys')->count())->toBe(8)   // the overlaps were skipped, never doubled
        ->and($allocated)->toHaveCount(8)->and($allocated->unique())->toHaveCount(8)   // ten customers, eight keys: eight different customers hold one each
        ->and(DB::table('license_keys')->where('status', 'available')->count())->toBe(0);
});
