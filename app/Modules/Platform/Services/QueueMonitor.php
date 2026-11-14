<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\HealthProbes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * The background work, for the queue monitor: per queue what is waiting, waiting for later, being worked on and how long the oldest has waited; the jobs
 * that failed lately, by kind; the outbox of events still to announce; and who runs the queue (a worker or the once-a-minute scheduler).
 * Only counts and job kinds: never a job's data, which may carry personal details.
 */
final class QueueMonitor
{
    public function __construct(private readonly HealthProbes $probes, private readonly Clock $clock) {}

    /**
     * @return array{connection: string, runner: string, scheduler: array{age: int|null, stale: bool}, queues: list<array{name: string, pending: int|null, delayed: int|null, running: int|null, oldest: int|null, state: string}>, failed: array{total: int, recent: int, kinds: list<array{kind: string, count: int, last: string}>}, outbox: array{pending: int, failed: int, oldest: int|null}, thresholds: array{warn: int, critical: int}}
     */
    public function snapshot(): array
    {
        $connection = (string) config('queue.default');
        $warn = (int) config('ops.queue_wait_warn_seconds', 300);
        $critical = (int) config('ops.queue_wait_critical_seconds', 900);
        $now = $this->clock->now();

        $queues = [];
        foreach ((array) config('ops.queues') as $name) {
            $name = (string) $name;
            try {
                $q = Queue::connection($connection);
                $oldestAt = $q->creationTimeOfOldestPendingJob($name);   // every driver answers these (database, redis, sync); a driver that cannot throws, caught below
                $row = [
                    'name' => $name,
                    'pending' => (int) $q->pendingSize($name),
                    'delayed' => (int) $q->delayedSize($name),
                    'running' => (int) $q->reservedSize($name),
                    'oldest' => $oldestAt === null ? null : max(0, $now->getTimestamp() - (int) $oldestAt),
                ];
                $row['state'] = match (true) {
                    $row['oldest'] !== null && $row['oldest'] >= $critical => 'critical',
                    $row['oldest'] !== null && $row['oldest'] >= $warn => 'warn',
                    default => 'ok',
                };
            } catch (Throwable) {
                $row = ['name' => $name, 'pending' => null, 'delayed' => null, 'running' => null, 'oldest' => null, 'state' => 'unknown'];   // the queue could not be asked
            }
            $queues[] = $row;
        }

        return [
            'connection' => $connection,
            'runner' => match (true) {
                $connection === 'sync' => 'sync',
                (bool) config('queue.run_from_scheduler') => 'scheduler',
                default => 'worker',
            },
            'scheduler' => ['age' => $this->probes->schedulerAge(), 'stale' => $this->probes->schedulerStale()],
            'queues' => $queues,
            'failed' => $this->failed($now),
            'outbox' => $this->outbox($now),
            'thresholds' => ['warn' => $warn, 'critical' => $critical],
        ];
    }

    /** @return array{total: int, recent: int, kinds: list<array{kind: string, count: int, last: string}>} */
    private function failed(CarbonImmutable $now): array
    {
        $since = $now->subDay()->format('Y-m-d H:i:s');
        $kinds = [];
        // the last day's failures, newest first and capped: only the job's kind is read from each, never its data
        foreach (DB::table('failed_jobs')->where('failed_at', '>=', $since)->orderByDesc('id')->limit(500)->get(['payload', 'failed_at']) as $row) {
            $payload = json_decode((string) $row->payload, true);
            $kind = class_basename(is_array($payload) ? (string) ($payload['displayName'] ?? 'Job') : 'Job');
            $kinds[$kind] ??= ['kind' => $kind, 'count' => 0, 'last' => (string) $row->failed_at];
            $kinds[$kind]['count']++;
        }
        usort($kinds, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return ['total' => (int) DB::table('failed_jobs')->count(), 'recent' => array_sum(array_column($kinds, 'count')), 'kinds' => $kinds];
    }

    /** @return array{pending: int, failed: int, oldest: int|null} */
    private function outbox(CarbonImmutable $now): array
    {
        // pending and failed only (index status, available_at): the done rows are the history and grow without end
        $oldest = DB::table('outbox_messages')->where('status', 'pending')->min('created_at');

        return [
            'pending' => (int) DB::table('outbox_messages')->where('status', 'pending')->count(),
            'failed' => (int) DB::table('outbox_messages')->where('status', 'failed')->count(),
            'oldest' => $oldest === null ? null : (int) CarbonImmutable::parse((string) $oldest, 'UTC')->diffInSeconds($now, true),
        ];
    }
}
