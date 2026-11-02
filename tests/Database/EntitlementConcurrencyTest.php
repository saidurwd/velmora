<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
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
use Illuminate\Support\Facades\DB;
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
