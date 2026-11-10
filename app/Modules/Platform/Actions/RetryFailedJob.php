<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Exceptions\OpsException;
use App\Modules\Platform\Services\FailedJobs;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/** Sends one failed job back to the queue, but only a kind that is safe to run again (config ops.retryable_jobs). Two people pressing at once send it once. */
final class RetryFailedJob
{
    public function __construct(private readonly FailedJobs $jobs, private readonly AuditLogger $audit) {}

    /** @throws OpsException */
    public function handle(Actor $actor, int $id): void
    {
        Cache::lock('ops-failed-job:'.$id, 10)->block(5, function () use ($actor, $id): void {
            $row = $this->jobs->find($id) ?? throw OpsException::gone();
            $class = FailedJobs::classOf((string) $row->payload);
            FailedJobs::isRetryable($class) || throw OpsException::notRetryable();

            Artisan::call('queue:retry', ['id' => [(string) $row->uuid]]);
            $this->audit->record('ops.failed_job_retried', 'failed_job', [], ['class' => $class, 'queue' => $row->queue], $actor, $id);
        });
    }
}
