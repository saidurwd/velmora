<?php

declare(strict_types=1);

use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Reporting\Actions\RefreshReportDay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * Working a report day out under REAL concurrency (Phase 10): a second process (its own PHP and MariaDB connection) is held back by the day's lock until the first
 * one has finished, and it reads the ledgers only after getting the lock, so it can never write older figures over newer ones.
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class]);
    $this->order = function (int $subtotal, string $status = 'confirmed'): int {
        return DB::table('orders')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()), 'order_number' => 'VM-RC-'.strtoupper(Str::random(8)), 'contact_name' => 'Test', 'contact_phone' => '+8801712345678', 'locale' => 'en', 'channel' => 'web',
            'status' => $status, 'cancelled_at' => $status === 'cancelled' ? '2026-03-11 00:00:00' : null, 'currency' => 'BDT', 'subtotal_minor' => $subtotal, 'discount_minor' => 0, 'shipping_minor' => 0, 'cod_fee_minor' => 0,
            'tax_minor' => 0, 'grand_total_minor' => $subtotal, 'tax_inclusive' => false, 'payment_method_code' => 'online', 'placed_at' => '2026-03-10 06:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $this->rebuild = function (): Process {
        $p = new Process([PHP_BINARY, 'artisan', 'reports:rebuild', '2026-03-10', '2026-03-10'], base_path(), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false',
        ]);

        return $p->setTimeout(120);
    };
});

it('makes a second run wait for the first, and the second run sees what changed meanwhile', function () {
    $keep = ($this->order)(1000);
    $drop = ($this->order)(500);
    app(RefreshReportDay::class)->handle('2026-03-10');
    expect((int) DB::table('report_daily_sales')->where('day', '2026-03-10')->sum('gross_minor'))->toBe(1500);

    // this connection is "the first run": it holds the day
    DB::beginTransaction();
    DB::table('report_day_status')->where('day', '2026-03-10')->lockForUpdate()->first();

    $second = ($this->rebuild)();
    $second->start();
    $deadline = microtime(true) + 6.0;
    while (microtime(true) < $deadline && $second->isRunning()) {
        usleep(200_000);
    }
    $waitedWhileHeld = $second->isRunning();   // after six seconds it has started and is stuck at the lock

    DB::table('orders')->where('id', $drop)->update(['status' => 'cancelled', 'cancelled_at' => '2026-03-11 00:00:00']);   // the ledgers change while it waits
    DB::commit();                                                                                                           // the first run lets go
    $second->wait();

    expect($waitedWhileHeld)->toBeTrue()->and($second->getExitCode())->toBe(0)
        ->and((int) DB::table('report_daily_sales')->where('day', '2026-03-10')->sum('gross_minor'))->toBe(1000)   // the later run read the NEW ledgers, after the lock
        ->and((int) DB::table('report_daily_sales')->where('day', '2026-03-10')->sum('cancelled_orders'))->toBe(1)
        ->and(DB::table('report_day_status')->where('day', '2026-03-10')->count())->toBe(1);
    expect($keep)->toBeInt();
});

it('ends with one set of rows when several runs start together', function () {
    ($this->order)(700);
    ($this->order)(300);
    $runs = array_map(fn () => ($this->rebuild)(), range(1, 5));
    foreach ($runs as $run) {
        $run->start();
    }
    foreach ($runs as $run) {
        $run->wait();
    }

    expect(array_map(fn (Process $p): string => $p->getExitCode().' '.substr($p->getErrorOutput().$p->getOutput(), 0, 600), $runs))->toBe(array_fill(0, 5, '0 Worked out 1 day(s).'."\n"))
        ->and(DB::table('report_daily_sales')->where('day', '2026-03-10')->count())->toBe(1)
        ->and((int) DB::table('report_daily_sales')->where('day', '2026-03-10')->sum('gross_minor'))->toBe(1000)
        ->and((int) DB::table('report_daily_sales')->where('day', '2026-03-10')->sum('orders'))->toBe(2)
        ->and(DB::table('report_day_status')->count())->toBe(1);
});
