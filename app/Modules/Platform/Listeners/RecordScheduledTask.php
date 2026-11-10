<?php

declare(strict_types=1);

namespace App\Modules\Platform\Listeners;

use App\Modules\Platform\Support\Redactor;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps one row per scheduled task: when it last started, finished, and how. A failure to write this is logged and never stops the scheduler.
 * A task that only hands a job to the queue counts as finished once handed over; the job's own failure shows in the failed-job list.
 */
final class RecordScheduledTask
{
    public function starting(ScheduledTaskStarting $e): void
    {
        $this->write($e->task, ['last_started_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'last_finished_at' => null, 'last_status' => 'started', 'last_error' => null, 'last_runtime_ms' => null]);
    }

    public function finished(ScheduledTaskFinished $e): void
    {
        $this->write($e->task, ['last_finished_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'last_status' => 'ok', 'last_runtime_ms' => (int) round($e->runtime * 1000)]);
    }

    public function failed(ScheduledTaskFailed $e): void
    {
        $this->write($e->task, ['last_finished_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'last_status' => 'failed', 'last_error' => mb_substr(Redactor::string((string) strtok($e->exception->getMessage(), "\n")), 0, 255)]);
    }

    public function skipped(ScheduledTaskSkipped $e): void
    {
        $this->write($e->task, ['last_status' => 'skipped']);
    }

    public static function nameOf(Event $task): string
    {
        return mb_substr((string) ($task->description ?: ($task->command ?? 'task')), 0, 120);
    }

    /** @param  array<string, mixed>  $fields */
    private function write(Event $task, array $fields): void
    {
        try {
            DB::table('scheduled_task_runs')->updateOrInsert(['name' => self::nameOf($task)], $fields + ['last_status' => 'started', 'updated_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        } catch (Throwable $e) {
            Log::warning('ops.scheduler_record_failed', ['task' => self::nameOf($task), 'error' => Redactor::string($e->getMessage())]);
        }
    }
}
