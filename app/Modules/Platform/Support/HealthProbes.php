<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** The small probes behind both the public readiness endpoint and the staff health page, so they can never disagree. A probe that throws counts as failed. */
final class HealthProbes
{
    public const SCHEDULER_STALE_AFTER = 180;

    public function database(): bool
    {
        return $this->probe(fn (): bool => DB::selectOne('select 1 as ok') !== null);
    }

    public function cache(): bool
    {
        return $this->probe(function (): bool {
            Cache::put('health:probe', '1', 10);

            return Cache::get('health:probe') === '1';
        });
    }

    public function storage(): bool
    {
        return $this->probe(function (): bool {
            $disk = Storage::disk((string) config('filesystems.default'));
            $disk->put('health/probe.txt', 'ok');
            $ok = $disk->get('health/probe.txt') === 'ok';
            $disk->delete('health/probe.txt');

            return $ok;
        });
    }

    /** Seconds since the scheduler last ticked, or null when it never has (or the cache was emptied). */
    public function schedulerAge(): ?int
    {
        $heartbeat = Cache::get('scheduler:heartbeat');

        return $heartbeat === null ? null : (int) now()->diffInSeconds($heartbeat, true);
    }

    public function schedulerStale(): bool
    {
        $age = $this->schedulerAge();

        return $age === null || $age > self::SCHEDULER_STALE_AFTER;
    }

    private function probe(callable $run): bool
    {
        try {
            return (bool) $run();
        } catch (Throwable) {
            return false;
        }
    }
}
