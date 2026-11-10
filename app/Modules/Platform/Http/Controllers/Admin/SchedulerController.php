<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Listeners\RecordScheduledTask;
use App\Modules\Platform\Support\HealthProbes;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/** Every scheduled task with its schedule, the next time it is due and how it last went. */
class SchedulerController
{
    public function index(Schedule $schedule, Kernel $console, HealthProbes $probes): View
    {
        $console->all();   // loads routes/console.php, where the schedule is defined (a web request does not do that by itself)

        $runs = DB::table('scheduled_task_runs')->get()->keyBy('name');
        $margin = (int) config('ops.scheduler.late_after_seconds');

        $tasks = [];
        foreach ($schedule->events() as $event) {
            $name = RecordScheduledTask::nameOf($event);
            $run = $runs->get($name);
            $timezone = (string) ($event->timezone ?? config('app.timezone'));
            $previous = CarbonImmutable::instance((new CronExpression($event->expression))->getPreviousRunDate('now', 0, true, $timezone))->utc();
            $started = $run?->last_started_at === null ? null : CarbonImmutable::parse((string) $run->last_started_at, 'UTC');

            $tasks[] = [
                'name' => $name, 'expression' => $event->expression, 'next' => CarbonImmutable::instance($event->nextRunDate())->utc(),
                'status' => $run?->last_status, 'started' => $started, 'finished' => $run?->last_finished_at === null ? null : CarbonImmutable::parse((string) $run->last_finished_at, 'UTC'),
                'runtime' => $run?->last_runtime_ms, 'error' => $run?->last_error,
                'late' => $started !== null && $started->lessThan($previous->subSeconds($margin)),   // it has missed at least one due time; a task that never ran is simply "not yet"
            ];
        }
        usort($tasks, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return view('platform::admin.ops.scheduler', ['tasks' => $tasks, 'age' => $probes->schedulerAge(), 'stale' => $probes->schedulerStale()]);
    }
}
