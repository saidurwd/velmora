<?php

declare(strict_types=1);

use App\Modules\Platform\Support\MariaDbVersion;
use App\Modules\Platform\Support\Preflight;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/* Phase 13 preparation: the server check, and the queue that runs from cron on shared hosting. */

beforeEach(function () {
    $root = sys_get_temp_dir().'/velmora-preflight-'.bin2hex(random_bytes(4));
    config(['filesystems.disks.media' => ['driver' => 'local', 'root' => $root.'/media'], 'filesystems.disks.private' => ['driver' => 'local', 'root' => $root.'/private']]);
    Storage::forgetDisk(['media', 'private']);
    // the built assets, in a folder the test owns: CI builds them only after the tests, and a developer's machine may or may not have them
    $this->publicRoot = $root.'/public';
    @mkdir($this->publicRoot.'/build', 0777, true);
    file_put_contents($this->publicRoot.'/build/manifest.json', '{}');
    app()->usePublicPath($this->publicRoot);
});

it('fails when the assets were not built, and passes when they were', function () {
    $check = fn () => collect((new Preflight)->run())->keyBy('name')['Built assets']['status'];
    expect($check())->toBe('ok');

    unlink($this->publicRoot.'/build/manifest.json');
    expect($check())->toBe('fail');
});

it('accepts only MariaDB 11.4.5 or newer', function (string $reported, bool $supported) {
    expect(MariaDbVersion::isSupported($reported))->toBe($supported);
})->with([
    ['11.4.5-MariaDB', true], ['11.4.13-MariaDB-ubu2404', true], ['5.5.5-11.8.2-MariaDB-log', true], ['12.1.2-MariaDB', true],
    ['11.4.4-MariaDB', false], ['10.11.9-MariaDB', false], ['10.6.18-MariaDB-cll-lve', false], ['8.4.2', false], ['8.0.36-MySQL Community', false], ['', false], ['garbage', false],
]);

it('reads the engine and the version out of what the server reports', function () {
    expect(MariaDbVersion::parse('5.5.5-10.6.18-MariaDB'))->toBe(['engine' => 'mariadb', 'version' => '10.6.18'])->and(MariaDbVersion::parse('8.0.36'))->toBe(['engine' => 'mysql', 'version' => '8.0.36'])->and(MariaDbVersion::parse('x'))->toBeNull();
});

it('passes every database check on this MariaDB and leaves nothing behind', function () {
    $results = collect((new Preflight)->run())->keyBy('name');

    foreach (['Database engine', 'Collation uca1400_ai_ci', 'Time periods (booking guard)', 'Triggers (append-only tables)', 'CHECK constraints', 'PHP version', 'PHP extensions'] as $name) {
        expect($results[$name]['status'])->toBe('ok', $name.': '.$results[$name]['detail']);
    }
    expect(DB::select("SHOW TABLES LIKE 'velmora\\_preflight\\_%'"))->toBe([]);
});

it('reports a failure, not a crash, when the database refuses a statement', function () {
    config(['database.connections.mariadb.database' => 'no_such_database_here']);
    DB::purge();

    $results = collect((new Preflight)->run())->keyBy('name');

    expect($results['Database connection']['status'])->toBe('fail')->and($results->has('Time periods (booking guard)'))->toBeFalse();
    config(['database.connections.mariadb.database' => env('DB_DATABASE')]);
    DB::purge();
});

it('warns when the scheduler has not run, and is happy when it did a moment ago', function () {
    Cache::forget('scheduler:heartbeat');
    $without = collect((new Preflight)->run())->keyBy('name')['Scheduler (cron)'];
    Cache::put('scheduler:heartbeat', now()->subSeconds(30)->toIso8601String(), 600);
    $with = collect((new Preflight)->run())->keyBy('name')['Scheduler (cron)'];

    expect($without['status'])->toBe('warn')->and($with['status'])->toBe('ok');
});

it('tells shared hosting what to do about the queue', function () {
    config(['queue.default' => 'database', 'queue.run_from_scheduler' => false]);
    $off = collect((new Preflight)->run())->keyBy('name')['Queue'];
    config(['queue.run_from_scheduler' => true]);
    $on = collect((new Preflight)->run())->keyBy('name')['Queue'];

    expect($off['status'])->toBe('warn')->and($off['detail'])->toContain('QUEUE_RUN_FROM_SCHEDULER')->and($on['status'])->toBe('ok');
});

it('exits non-zero when something fails and zero when nothing does', function () {
    config(['cache.default' => 'file']);   // the test environment keeps its cache in memory, which the check (rightly) refuses
    $this->artisan('velmora:preflight')->assertSuccessful();

    config(['cache.default' => 'array']);
    $this->artisan('velmora:preflight')->assertFailed();
});

it('drains the queue from the scheduler only when asked to', function () {
    $event = fn () => collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'queue:drain');

    config(['queue.run_from_scheduler' => false]);
    expect($event()->filtersPass(app()))->toBeFalse();

    config(['queue.run_from_scheduler' => true]);
    expect($event()->filtersPass(app()))->toBeTrue()->and($event()->command)->toContain('queue:work --stop-when-empty --max-time=50');
});

it('fails, with the shared-hosting remedy, when a storage bucket cannot be reached, and passes when files can be written', function () {
    expect(collect((new Preflight)->run())->keyBy('name')['File storage: Private files']['status'])->toBe('ok');

    config(['filesystems.disks.private' => ['driver' => 's3', 'key' => 'x', 'secret' => 'x', 'region' => 'us-east-1', 'bucket' => 'none', 'endpoint' => 'http://127.0.0.1:1', 'use_path_style_endpoint' => true, 'throw' => true]]);
    Storage::forgetDisk('private');
    $result = collect((new Preflight)->run())->keyBy('name')['File storage: Private files'];

    expect($result['status'])->toBe('fail')->and($result['detail'])->toContain('PRIVATE_DRIVER=local');
});
