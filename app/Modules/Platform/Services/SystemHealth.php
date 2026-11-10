<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Support\HealthProbes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/** Everything the staff health page shows, in one read. Nothing here is secret: no addresses, passwords or keys. */
final class SystemHealth
{
    public function __construct(private readonly HealthProbes $probes) {}

    /**
     * @return array{probes: array<string, bool>, scheduler: array{age: int|null, stale: bool}, failedJobs: array{count: int, oldest: CarbonImmutable|null}, outbox: array{pending: int, failed: int, oldestAge: int|null}, queues: array<string, int|null>, runtime: array<string, mixed>, warnings: list<string>}
     */
    public function snapshot(): array
    {
        $failed = DB::table('failed_jobs')->selectRaw('COUNT(*) AS n, MIN(failed_at) AS oldest')->first() ?? (object) ['n' => 0, 'oldest' => null];
        $outbox = DB::table('outbox_messages')->where('status', 'pending')->selectRaw('COUNT(*) AS n, MIN(created_at) AS oldest')->first() ?? (object) ['n' => 0, 'oldest' => null];

        $queues = [];
        foreach ((array) config('ops.queues') as $queue) {
            try {
                $queues[(string) $queue] = Queue::size((string) $queue);
            } catch (Throwable) {
                $queues[(string) $queue] = null;   // the queue could not be asked
            }
        }

        $runtime = [
            'php' => PHP_VERSION, 'laravel' => app()->version(), 'database' => (string) DB::selectOne('select version() as v')->v,
            'environment' => app()->environment(), 'debug' => (bool) config('app.debug'), 'maintenance' => app()->isDownForMaintenance(),
            'queue' => (string) config('queue.default'),
        ];

        $warnings = [];
        $runtime['environment'] === 'production' && $runtime['debug'] && $warnings[] = 'debug_on_in_production';
        $runtime['maintenance'] && $warnings[] = 'maintenance_on';

        return [
            'probes' => ['database' => $this->probes->database(), 'cache' => $this->probes->cache(), 'storage' => $this->probes->storage(), 'queue' => ! in_array(null, $queues, true)],
            'scheduler' => ['age' => $this->probes->schedulerAge(), 'stale' => $this->probes->schedulerStale()],
            'failedJobs' => ['count' => (int) $failed->n, 'oldest' => $failed->oldest === null ? null : CarbonImmutable::parse((string) $failed->oldest, 'UTC')],
            'outbox' => [
                'pending' => (int) $outbox->n,
                'failed' => (int) DB::table('outbox_messages')->where('status', 'failed')->count(),
                'oldestAge' => $outbox->oldest === null ? null : (int) CarbonImmutable::parse((string) $outbox->oldest, 'UTC')->diffInSeconds(now(), true),
            ],
            'queues' => $queues,
            'runtime' => $runtime,
            'warnings' => $warnings,
        ];
    }
}
