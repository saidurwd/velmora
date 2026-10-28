<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the platform tables with the required constraints', function () {
    foreach (['languages', 'settings', 'audit_events', 'idempotency_keys', 'outbox_messages', 'consumed_events'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }

    $unique = DB::select("select index_name from information_schema.statistics where table_schema = database() and table_name = 'idempotency_keys' and non_unique = 0 and index_name <> 'PRIMARY'");
    expect($unique)->not->toBeEmpty();
});

it('uses the project collation and engine on its tables', function () {
    $rows = DB::select("select table_name, engine, table_collation from information_schema.tables where table_schema = database() and table_name in ('audit_events','outbox_messages','settings')");

    foreach ($rows as $row) {
        expect($row->engine)->toBe('InnoDB')->and($row->table_collation)->toBe('utf8mb4_uca1400_ai_ci');
    }
});

it('rejects invalid statuses through CHECK constraints', function () {
    DB::table('outbox_messages')->insert(['public_id' => str_repeat('a', 26), 'event_name' => 'x', 'payload' => '{}', 'status' => 'bogus', 'available_at' => now(), 'created_at' => now()]);
})->throws(QueryException::class);

it('never stores money or quantities as floating point (BD-20)', function () {
    $bad = DB::select("select table_name, column_name, data_type from information_schema.columns where table_schema = database() and data_type in ('float','double','decimal')");

    expect($bad)->toBe([]);
});

it('runs the session in UTC', function () {
    expect(DB::selectOne('select @@session.time_zone as tz')->tz)->toBe('+00:00');
});
