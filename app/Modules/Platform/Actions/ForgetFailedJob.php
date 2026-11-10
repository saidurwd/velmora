<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Exceptions\OpsException;
use App\Modules\Platform\Services\FailedJobs;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Deletes one failed job from the list (for a job nobody will retry). Any kind may be forgotten; the class and the first line of the error are kept in the audit log. */
final class ForgetFailedJob
{
    public function __construct(private readonly FailedJobs $jobs, private readonly AuditLogger $audit) {}

    /** @throws OpsException */
    public function handle(Actor $actor, int $id): void
    {
        Cache::lock('ops-failed-job:'.$id, 10)->block(5, function () use ($actor, $id): void {
            $row = $this->jobs->find($id) ?? throw OpsException::gone();
            DB::table('failed_jobs')->where('id', $id)->delete();
            $this->audit->record('ops.failed_job_forgotten', 'failed_job', ['class' => FailedJobs::classOf((string) $row->payload), 'queue' => $row->queue, 'error' => FailedJobs::firstLine((string) $row->exception)], [], $actor, $id);
        });
    }
}
