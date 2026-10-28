<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class HealthController
{
    /** Liveness: the process answers. No dependencies checked. */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /** Readiness: the dependencies this instance needs to serve traffic. */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::selectOne('select 1 as ok') !== null),
            'cache' => $this->check(function (): bool {
                Cache::put('health:probe', '1', 10);

                return Cache::get('health:probe') === '1';
            }),
            'storage' => $this->check(function (): bool {
                $disk = Storage::disk((string) config('filesystems.default'));
                $disk->put('health/probe.txt', 'ok');
                $ok = $disk->get('health/probe.txt') === 'ok';
                $disk->delete('health/probe.txt');

                return $ok;
            }),
        ];

        $heartbeat = Cache::get('scheduler:heartbeat');
        $schedulerStale = $heartbeat === null || now()->diffInSeconds($heartbeat, true) > 180;

        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ok ? 'ready' : 'degraded',
            'checks' => $checks,
            // informational only: a stale scheduler is an alert, not a reason to stop serving traffic
            'scheduler' => $schedulerStale ? 'stale' : 'ok',
        ], $ok ? 200 : 503);
    }

    private function check(callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable) {
            return false;
        }
    }
}
