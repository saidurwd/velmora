<?php

declare(strict_types=1);

use App\Modules\Platform\Events\OutboxProbe;
use App\Modules\Platform\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

it('delivers each message exactly once across parallel worker processes (SKIP LOCKED)', function () {
    Queue::fake();

    foreach (range(1, 60) as $i) {
        DB::transaction(fn () => app(Outbox::class)->record(new OutboxProbe("probe-{$i}")));
    }

    expect(DB::table('outbox_messages')->where('status', 'pending')->count())->toBe(60);

    // Real, separate OS processes with their own DB connections. The environment is forced to the test schema.
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'];
    $workers = array_map(function () use ($env) {
        $process = new Process([PHP_BINARY, 'artisan', 'outbox:dispatch', '--limit=5'], base_path(), $env);
        $process->start();

        return $process;
    }, range(1, 4));

    foreach ($workers as $worker) {
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
    }

    expect(DB::table('outbox_messages')->where('status', 'done')->count())->toBe(60)
        ->and(DB::table('outbox_messages')->where('attempts', '!=', 1)->count())->toBe(0)  // never delivered twice
        ->and(DB::table('consumed_events')->where('consumer', 'platform.probe')->count())->toBe(60);
});
