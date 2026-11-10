<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Web;

use App\Modules\Platform\Support\HealthProbes;
use Illuminate\Http\JsonResponse;

class HealthController
{
    /** Liveness: the process answers. No dependencies checked. */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /** Readiness: the dependencies this instance needs to serve traffic. */
    public function ready(HealthProbes $probes): JsonResponse
    {
        $checks = ['database' => $probes->database(), 'cache' => $probes->cache(), 'storage' => $probes->storage()];
        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ok ? 'ready' : 'degraded',
            'checks' => $checks,
            // informational only: a stale scheduler is an alert, not a reason to stop serving traffic
            'scheduler' => $probes->schedulerStale() ? 'stale' : 'ok',
        ], $ok ? 200 : 503);
    }
}
