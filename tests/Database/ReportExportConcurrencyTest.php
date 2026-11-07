<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * Building one report file under REAL concurrency (Phase 10): several workers (each its own PHP process and MariaDB connection) are given the same waiting export at the same
 * instant. Rule proved: exactly one of them builds it, so there is one file and one finished export, never two files or a half-written one.
 */

beforeEach(function () {
    reportWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    ($this->scenario)();
    $staff = catalogStaff(['reports.view', 'reports.export', 'reports.finance.view']);
    $this->exportId = DB::table('report_exports')->insertGetId([
        'public_id' => strtolower((string) Str::ulid()), 'requested_by' => $staff->id, 'report_code' => 'sales_by_day', 'filters' => json_encode(['from' => '2026-03-10', 'to' => '2026-03-11']), 'locale' => 'en',
        'with_finance' => true, 'with_pii' => false, 'status' => 'requested', 'requested_at' => now(),
    ]);
});

it('builds a waiting export exactly once when six workers take it at the same instant', function () {
    // the workers write their file to the local private folder (REPORT_EXPORT_DISK), which the test looks in afterwards
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false', 'REPORT_EXPORT_DISK' => 'local'];
    $startAt = microtime(true) + 8.0;
    $before = glob(storage_path('app/private/reports/exports/*') ?: '') ?: [];
    $runs = array_map(function () use ($env, $startAt) {
        $p = new Process([PHP_BINARY, 'artisan', 'reports:build-export', (string) $this->exportId, '--start-at='.$startAt], base_path(), $env);
        $p->setTimeout(180)->start();

        return $p;
    }, range(1, 6));
    foreach ($runs as $run) {
        $run->wait();
    }

    $export = DB::table('report_exports')->where('id', $this->exportId)->first();
    $after = array_values(array_diff(glob(storage_path('app/private/reports/exports/*') ?: '') ?: [], $before));
    try {
        expect(array_map(fn (Process $p): ?int => $p->getExitCode(), $runs))->toBe([0, 0, 0, 0, 0, 0])
            ->and($export->status)->toBe('ready')->and($export->row_count)->toBe(2)->and($export->file_key)->not->toBeNull()
            ->and($after)->toHaveCount(1)                                         // one file, not six
            ->and(basename($after[0]))->toBe(basename((string) $export->file_key))   // and it is the one the export points at
            ->and(DB::table('report_exports')->where('id', $this->exportId)->count())->toBe(1);
    } finally {
        array_map('unlink', $after);
    }
});
