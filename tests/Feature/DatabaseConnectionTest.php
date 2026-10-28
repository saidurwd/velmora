<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('runs on MariaDB against the dedicated test schema', function () {
    $row = DB::selectOne('select version() as v, database() as d, @@transaction_isolation as iso, @@collation_database as c');

    expect($row->v)->toContain('MariaDB')
        ->and($row->d)->toBe('velmora_test')
        ->and($row->c)->toBe('utf8mb4_uca1400_ai_ci');
});

it('stores timestamps in UTC', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('app.display_timezone'))->toBe('Asia/Dhaka');
});
