<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Inventory\Contracts\StockInitialiser;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Inventory\Services\StockLedgerVerifier;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderAddress;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Pricing\Services\BreakdownCalculator;
use App\Modules\Shipping\Database\Seeders\CourierSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Parcels under REAL concurrency (Phase 7 gate): every probe is its own PHP process with its own MariaDB connection, all released at the same
 * instant. Rules proved: a parcel and a cancellation of its order never both win; never more is sent than was ordered; a parcel is cancelled or handed
 * over, not both; and the stock ledger balances after every race.
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, CourierSeeder::class, StockLocationSeeder::class]);
    $system = Actor::system('t');
    $category = app(SaveCategory::class)->handle($system, null, null, [new TranslationInput('en', 'General')]);
    $p = app(SaveProduct::class)->handle($system, null, new ProductData(ProductType::Physical, [new TranslationInput('en', 'Cotton Tee'), new TranslationInput('bn', 'টি')], categoryIds: [$category->id], defaultVariant: new VariantData('TEE-1', trackInventory: true)));
    $this->tee = (int) $p->variants()->value('id');
    app(StockInitialiser::class)->openingStock($system, $this->tee, 100);

    /** A confirmed cash order of $qty tees with its stock held. Returns [public id, order id, order item id]. */
    $this->order = function (int $qty) use ($system): array {
        $breakdown = (new BreakdownCalculator)->calculate([['variantId' => $this->tee, 'quantity' => $qty, 'unitMinor' => 65000, 'lineDiscountMinor' => 0, 'taxRateBp' => 0]], false, 0, 6000, 2000);
        $address = new NewOrderAddress('shipping', 'Rahim Uddin', '+8801712345678', 3, 30, 300, ['division' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'district' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'upazila' => ['en' => 'Dhanmondi', 'bn' => 'ধানমন্ডি']], 'House 5', '1205', 'Dhanmondi', null);
        $order = app(CreateOrder::class)->handle(new NewOrder(Actor::guest('checkout'), null, 'Rahim Uddin', '+8801712345678', null, OrderChannel::Web, 'en', 'cod', 'standard', $breakdown, [new NewOrderLine($this->tee, 'physical', ['en' => 'Cotton Tee', 'bn' => 'টি'], 'TEE-1')], [$address]));
        app(StockReservations::class)->holdForOrder([$this->tee => $qty], $order->public_id);
        app(TransitionOrder::class)->status($system, $order->id, OrderStatus::Confirmed, 'test');

        return [$order->public_id, $order->id, (int) DB::table('order_items')->where('order_id', $order->id)->value('id')];
    };
    $this->stock = fn () => DB::table('stock_levels')->where('variant_id', $this->tee)->first(['on_hand', 'reserved']);
});

/**
 * @param  list<array{0: string, 1: list<string>}>  $jobs  [artisan command, arguments]
 * @return list<array<string, mixed>>
 */
function runShippingProbes(array $jobs): array
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

it('lets a parcel and the cancellation of its order race, and exactly one wins, with the stock right either way', function () {
    $outcomes = [];
    foreach (range(1, 4) as $round) {
        [$public, $id, $item] = ($this->order)(2);
        $before = ($this->stock)();

        runShippingProbes([
            ['shipping:probe', ['ship', "--order={$public}", "--lines={$item}:2"]],
            ['shipping:probe', ['ship', "--order={$public}", "--lines={$item}:2"]],
            ['orders:probe', ['status', "--order={$id}", '--to=cancelled', '--reason=Changed my mind']],
        ]);

        $order = DB::table('orders')->where('id', $id)->first(['status', 'fulfillment_status']);
        $parcels = DB::table('shipments')->where('order_id', $id)->count();
        $after = ($this->stock)();
        if ($order->status === 'cancelled') {
            $outcomes['cancelled'] = true;
            expect($parcels)->toBe(0, "round {$round}: cancelled order must have no parcel")->and($order->fulfillment_status)->toBe('unfulfilled')
                ->and([$after->on_hand, $after->reserved])->toBe([$before->on_hand, $before->reserved - 2], "round {$round}: the hold goes back");   // 2 released, none shipped
        } else {
            $outcomes['shipped'] = true;
            expect($parcels)->toBe(1, "round {$round}: exactly one parcel")->and($order->fulfillment_status)->toBe('fulfilled')
                ->and([$after->on_hand, $after->reserved])->toBe([$before->on_hand - 2, $before->reserved - 2], "round {$round}: the stock left once");
        }
        expect(DB::table('order_items')->where('order_id', $id)->value('fulfilled_qty'))->toBe($parcels === 1 ? 2 : 0)->and(app(StockLedgerVerifier::class)->verify())->toBe([], "round {$round}: the ledger balances");
    }
    expect($outcomes)->not->toBe([]);
});

it('never sends more than was ordered, however many people press ship at once', function () {
    [$public, $id, $item] = ($this->order)(3);

    $results = runShippingProbes(array_map(fn () => ['shipping:probe', ['ship', "--order={$public}", "--lines={$item}:2"]], range(1, 5)));

    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1)->and(array_unique(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')))->toBe(['quantity_exceeds_remaining'])
        ->and(DB::table('order_items')->where('id', $item)->value('fulfilled_qty'))->toBe(2)->and(DB::table('shipments')->count())->toBe(1);

    $more = runShippingProbes(array_map(fn () => ['shipping:probe', ['ship', "--order={$public}", "--lines={$item}:1"]], range(1, 4)));

    expect(count(array_filter($more, fn ($r) => $r['ok'])))->toBe(1)->and(DB::table('order_items')->where('id', $item)->value('fulfilled_qty'))->toBe(3)->and(DB::table('shipments')->count())->toBe(2)
        ->and((int) DB::table('shipments')->sum('cod_amount_minor'))->toBe((int) DB::table('orders')->where('id', $id)->value('grand_total_minor'))   // the parcels' cash adds up to the order total
        ->and(($this->stock)()->on_hand)->toBe(97)->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
});

it('lets a parcel be cancelled or handed over, never both, when both are pressed at the same instant', function () {
    foreach (range(1, 3) as $round) {
        [$public, $id, $item] = ($this->order)(1);
        $shipment = runShippingProbes([['shipping:probe', ['ship', "--order={$public}", "--lines={$item}:1"]]])[0]['shipment'];

        $results = runShippingProbes([
            ['shipping:probe', ['cancel', "--shipment={$shipment}"]],
            ['shipping:probe', ['handover', "--shipment={$shipment}"]],
            ['shipping:probe', ['cancel', "--shipment={$shipment}"]],
            ['shipping:probe', ['handover', "--shipment={$shipment}"]],
        ]);

        $row = DB::table('shipments')->where('public_id', $shipment)->first();
        expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1, "round {$round}: exactly one wins")->and($row->status)->toBeIn(['cancelled', 'handed_over']);
        if ($row->status === 'cancelled') {
            expect(DB::table('order_items')->where('id', $item)->value('fulfilled_qty'))->toBe(0)->and(DB::table('orders')->where('id', $id)->value('fulfillment_status'))->toBe('unfulfilled');
        } else {
            expect(DB::table('order_items')->where('id', $item)->value('fulfilled_qty'))->toBe(1)->and(DB::table('orders')->where('id', $id)->value('fulfillment_status'))->toBe('fulfilled');
        }
        expect(app(StockLedgerVerifier::class)->verify())->toBe([], "round {$round}: the ledger balances");
    }
});

it('books a parcel with its courier once when several people press book', function () {
    [$public, $id, $item] = ($this->order)(1);
    $account = DB::table('courier_accounts')->insertGetId(['public_id' => $accountId = strtolower((string) Str::ulid()), 'courier' => 'fake', 'label' => 'Fake', 'is_active' => 1, 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()]);
    $shipment = runShippingProbes([['shipping:probe', ['ship', "--order={$public}", "--lines={$item}:1", "--account={$accountId}"]]])[0]['shipment'];
    DB::table('shipments')->where('public_id', $shipment)->update(['updated_at' => now()]);

    runShippingProbes(array_map(fn () => ['shipping:probe', ['book', "--shipment={$shipment}"]], range(1, 5)));

    $row = DB::table('shipments')->where('public_id', $shipment)->first();
    expect($row->courier_consignment_id)->not->toBeNull()->and(DB::table('shipment_events')->where('shipment_id', $row->id)->where('provider_status', 'booked')->count())->toBe(1)->and($account)->toBeInt();
});

it('makes a cancellation of the order wait while a parcel is being made, and a parcel wait while the order is being cancelled (the order row is the lock)', function () {
    [$public, $id, $item] = ($this->order)(1);
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    // 1. we hold the order the way CreateShipment does; another process tries to cancel it and must wait
    DB::beginTransaction();
    app(OrderFulfilment::class)->lock($public);
    $cancel = new Process([PHP_BINARY, 'artisan', 'orders:probe', 'status', "--order={$id}", '--to=cancelled', '--reason=Changed my mind'], base_path(), $env);
    $cancel->setTimeout(120)->start();
    $deadline = microtime(true) + 6.0;
    while (microtime(true) < $deadline && $cancel->isRunning()) {
        usleep(200_000);
    }
    $waited = $cancel->isRunning();
    DB::rollBack();
    $cancel->wait();

    expect($waited)->toBeTrue('the cancellation must wait for the order row')->and(DB::table('orders')->where('id', $id)->value('status'))->toBe('cancelled');

    // 2. the other way round: a process holds the order the way a cancellation does; a parcel must wait
    [$public2, $id2, $item2] = ($this->order)(1);
    DB::beginTransaction();
    DB::table('orders')->where('id', $id2)->lockForUpdate()->first();
    $ship = new Process([PHP_BINARY, 'artisan', 'shipping:probe', 'ship', "--order={$public2}", "--lines={$item2}:1"], base_path(), $env);
    $ship->setTimeout(120)->start();
    $deadline = microtime(true) + 6.0;
    while (microtime(true) < $deadline && $ship->isRunning()) {
        usleep(200_000);
    }
    $waited = $ship->isRunning();
    DB::rollBack();
    $ship->wait();

    expect($waited)->toBeTrue('making a parcel must wait for the order row')->and(DB::table('shipments')->where('order_id', $id2)->count())->toBe(1)->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
});
