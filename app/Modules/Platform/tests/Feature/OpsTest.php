<?php

declare(strict_types=1);

use App\Modules\Engagement\Jobs\SendCartRemindersJob;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Notifications\Jobs\DeliverNotificationJob;
use App\Modules\Payments\Jobs\PollRefundsJob;
use App\Modules\Platform\Actions\ForgetFailedJob;
use App\Modules\Platform\Actions\RetryFailedJob;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Exceptions\OpsException;
use App\Modules\Platform\Listeners\RecordScheduledTask;
use App\Modules\Platform\Support\HealthProbes;
use App\Modules\Reporting\Jobs\RefreshCohortsJob;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * Phase 10, slice 4b: scheduler status, system health and the failed-job monitor.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->viewer = catalogStaff(['system.view', 'queues.view']);
    $this->operator = catalogStaff(['system.view', 'queues.view', 'queues.manage']);
    $this->actor = Actor::system('t');
    $this->audits = fn (string $action) => DB::table('audit_events')->where('action', $action)->orderBy('id')->get();
    $this->task = fn (string $name = 'demo:task') => app(Schedule::class)->call(fn () => 1)->name($name);
    $this->run = fn (string $name) => DB::table('scheduled_task_runs')->where('name', $name)->first();

    /** A failed job row. $over: class, queue, error, payload. Returns its id. */
    $this->failed = function (array $o = []): int {
        $class = $o['class'] ?? RefreshCohortsJob::class;

        return DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(), 'connection' => $o['connection'] ?? 'database', 'queue' => $o['queue'] ?? 'low',
            'payload' => $o['payload'] ?? json_encode(['uuid' => (string) Str::uuid(), 'displayName' => $class, 'job' => 'Illuminate\Queue\CallQueuedHandler@call', 'maxTries' => null, 'attempts' => 1, 'data' => ['commandName' => $class, 'command' => ($this->serialised)($class), 'note' => 'private-data-01712345678']]),
            'exception' => $o['error'] ?? "RuntimeException: boom (password=hunter2)\n#0 /app/very/long/trace.php(12): thing()\n#1 {main}", 'failed_at' => $o['failed_at'] ?? now(),
        ]);
    };
    $this->as = fn ($who) => $this->actingAs($who, 'admin');
    /** What a real queued job's payload carries: the job itself, serialised. Jobs that need arguments get a stand-in (they are never run here). */
    $this->serialised = function (string $class): string {
        try {
            return serialize(new $class);
        } catch (Throwable) {
            return serialize(new stdClass);
        }
    };
});

describe('the scheduler record', function () {
    it('keeps one row per task: started, then finished with how long it took', function () {
        $task = ($this->task)('demo:task');

        event(new ScheduledTaskStarting($task));
        $row = ($this->run)('demo:task');
        expect($row->last_status)->toBe('started')->and($row->last_started_at)->not->toBeNull()->and($row->last_finished_at)->toBeNull();

        event(new ScheduledTaskFinished($task, 0.25));
        $row = ($this->run)('demo:task');
        expect($row->last_status)->toBe('ok')->and($row->last_runtime_ms)->toBe(250)->and($row->last_finished_at)->not->toBeNull()->and(DB::table('scheduled_task_runs')->where('name', 'demo:task')->count())->toBe(1);

        event(new ScheduledTaskStarting($task));   // the next tick: same row, back to "started", the old finish cleared
        $row = ($this->run)('demo:task');
        expect($row->last_status)->toBe('started')->and($row->last_finished_at)->toBeNull()->and($row->last_error)->toBeNull();
    });

    it('records a failure with the first line of the error only, secrets removed', function () {
        $task = ($this->task)('demo:fail');
        event(new ScheduledTaskStarting($task));
        event(new ScheduledTaskFailed($task, new RuntimeException("Gateway down (store_passwd=hunter2) call 01712345678\nsecond line with detail")));

        $row = ($this->run)('demo:fail');
        expect($row->last_status)->toBe('failed')->and($row->last_error)->toContain('Gateway down')->not->toContain('hunter2')->not->toContain('01712345678')->not->toContain('second line');
    });

    it('records a skipped task and names a task by its name, then its command', function () {
        $named = ($this->task)('demo:skip');
        event(new ScheduledTaskSkipped($named));
        expect(($this->run)('demo:skip')->last_status)->toBe('skipped');

        $unnamed = app(Schedule::class)->command('inspire');
        expect(RecordScheduledTask::nameOf($unnamed))->toContain('inspire')->and(RecordScheduledTask::nameOf(($this->task)(str_repeat('x', 300))))->toHaveLength(120);
    });

    it('never stops the scheduler when the record cannot be written, and says so in the log', function () {
        Log::spy();
        $broken = true;
        DB::beforeExecuting(function (string $query) use (&$broken): void {
            if ($broken && str_contains($query, 'scheduled_task_runs')) {
                throw new RuntimeException('the database is unwell');
            }
        });

        event(new ScheduledTaskStarting(($this->task)('demo:unwell')));   // must not throw

        $broken = false;
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c) => $m === 'ops.scheduler_record_failed' && $c['task'] === 'demo:unwell');
        expect(($this->run)('demo:unwell'))->toBeNull();
    });
});

describe('the scheduler page', function () {
    it('lists the real schedule with the next time each task is due, and what has not run yet', function () {
        $html = ($this->as)($this->viewer)->get('/admin/ops/scheduler')->assertOk()->assertSee('Scheduled tasks')
            ->assertSee('cms:publish-due')->assertSee('ops:integrity')->assertSee('outbox:dispatch')->assertSee('* * * * *')->assertSee('40 3 * * *')->assertSee('Not yet')->getContent();

        expect(strpos($html, 'cms:publish-due'))->toBeLessThan(strpos($html, 'ops:integrity'))->and(strpos($html, 'ops:integrity'))->toBeLessThan(strpos($html, 'outbox:dispatch'));   // by name
    });

    it('shows how the last run went, flags a task that is late and one that failed', function () {
        $now = now('UTC');
        DB::table('scheduled_task_runs')->insert([
            ['name' => 'outbox:dispatch', 'last_started_at' => $now->copy()->subSeconds(20)->format('Y-m-d H:i:s.u'), 'last_finished_at' => $now->copy()->subSeconds(19)->format('Y-m-d H:i:s.u'), 'last_status' => 'ok', 'last_error' => null, 'last_runtime_ms' => 40, 'updated_at' => $now],
            ['name' => 'cms:publish-due', 'last_started_at' => $now->copy()->subMinutes(30)->format('Y-m-d H:i:s.u'), 'last_finished_at' => $now->copy()->subMinutes(30)->format('Y-m-d H:i:s.u'), 'last_status' => 'ok', 'last_error' => null, 'last_runtime_ms' => 10, 'updated_at' => $now],
            ['name' => 'engagement:back-in-stock', 'last_started_at' => $now->copy()->subMinutes(2)->format('Y-m-d H:i:s.u'), 'last_finished_at' => $now->copy()->subMinutes(2)->format('Y-m-d H:i:s.u'), 'last_status' => 'failed', 'last_error' => 'Gateway down', 'last_runtime_ms' => null, 'updated_at' => $now],
        ]);

        $html = ($this->as)($this->viewer)->get('/admin/ops/scheduler')->assertOk()->assertSee('Gateway down')->assertSee('Failed')->assertSee('40 ms')->getContent();
        $row = fn (string $name) => (string) (preg_match('#<tr[^>]*>(?:(?!</tr>).)*'.preg_quote($name, '#').'(?:(?!</tr>).)*</tr>#s', $html, $m) ? $m[0] : '');

        expect($row('cms:publish-due'))->toContain('Late')                  // every minute, last started half an hour ago
            ->and($row('outbox:dispatch'))->not->toContain('Late')           // started seconds ago
            ->and($row('ops:integrity'))->not->toContain('Late');            // a daily task that has simply not run yet is not late
    });

    it('says how long ago the scheduler last ticked, and warns when it has stopped', function () {
        Cache::forget('scheduler:heartbeat');
        ($this->as)($this->viewer)->get('/admin/ops/scheduler')->assertOk()->assertSee('has not ticked yet');

        Cache::put('scheduler:heartbeat', now()->subSeconds(30)->toIso8601String(), 600);
        ($this->as)($this->viewer)->get('/admin/ops/scheduler')->assertOk()->assertSee('last ticked 30 seconds ago')->assertDontSee('That is too long');

        Cache::put('scheduler:heartbeat', now()->subMinutes(10)->toIso8601String(), 600);
        ($this->as)($this->viewer)->get('/admin/ops/scheduler')->assertOk()->assertSee('That is too long');
    });
});

describe('the health page', function () {
    it('shows the checks, the waiting work and this server, without any secret', function () {
        config(['database.connections.mariadb.password' => 'Sup3r-secret-db-password', 'mail.mailers.smtp.password' => 'Sup3r-secret-mail-password']);
        Cache::put('scheduler:heartbeat', now()->toIso8601String(), 600);
        DB::table('outbox_messages')->insert([
            ['public_id' => strtolower((string) Str::ulid()), 'event_name' => 'x.y', 'payload' => '{}', 'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now()->subMinutes(5)],
            ['public_id' => strtolower((string) Str::ulid()), 'event_name' => 'x.y', 'payload' => '{}', 'status' => 'failed', 'attempts' => 10, 'available_at' => now(), 'created_at' => now()],
            ['public_id' => strtolower((string) Str::ulid()), 'event_name' => 'x.y', 'payload' => '{}', 'status' => 'done', 'attempts' => 1, 'available_at' => now(), 'created_at' => now()],
        ]);
        ($this->failed)();
        ($this->failed)();

        $html = ($this->as)($this->viewer)->get('/admin/ops/health')->assertOk()->assertSee('System health')->assertSee('Database')->assertSee('File storage')->assertSee('Job queue')
            ->assertSee('1 waiting, 1 given up')->assertSee('Queue “default”')->assertSee(PHP_VERSION)->assertSee(app()->version())->getContent();

        expect($html)->not->toContain((string) config('app.key'))->not->toContain('Sup3r-secret-db-password')->not->toContain('Sup3r-secret-mail-password')->and(substr_count($html, 'text-danger'))->toBe(0);
    });

    it('counts failed jobs, with the oldest, and links to them only for those who may see them', function () {
        ($this->failed)(['failed_at' => '2026-12-01 06:00:00']);
        ($this->failed)(['failed_at' => '2026-12-02 06:00:00']);

        ($this->as)($this->viewer)->get('/admin/ops/health')->assertOk()->assertSee('Failed jobs')->assertSee('oldest')->assertSee('/admin/ops/failed-jobs', false);
        ($this->as)(catalogStaff(['system.view']))->get('/admin/ops/health')->assertOk()->assertDontSee('/admin/ops/failed-jobs', false);
    });

    it('warns when debug is on in production, and when the shop is closed', function () {
        app()->detectEnvironment(fn () => 'production');
        config(['app.debug' => true]);
        ($this->as)($this->viewer)->get('/admin/ops/health')->assertOk()->assertSee('Debug mode is on in production');

        config(['app.debug' => false]);
        ($this->as)($this->viewer)->get('/admin/ops/health')->assertOk()->assertDontSee('Debug mode is on in production');
        app()->detectEnvironment(fn () => 'testing');
        config(['app.debug' => true]);
        ($this->as)($this->viewer)->get('/admin/ops/health')->assertOk()->assertDontSee('Debug mode is on in production');   // fine on a developer's machine

        Artisan::call('down');
        ($this->as)($this->viewer)->get('/admin/ops/health')->assertOk()->assertSee('closed for maintenance');
        Artisan::call('up');
    });

    it('treats a probe that throws as failed, and a stale scheduler as stale', function () {
        config(['filesystems.default' => 'no-such-disk']);
        $probes = app(HealthProbes::class);
        expect($probes->storage())->toBeFalse()->and($probes->database())->toBeTrue();

        Cache::forget('scheduler:heartbeat');
        expect($probes->schedulerAge())->toBeNull()->and($probes->schedulerStale())->toBeTrue();
        Cache::put('scheduler:heartbeat', now()->subSeconds(HealthProbes::SCHEDULER_STALE_AFTER + 5)->toIso8601String(), 600);
        expect($probes->schedulerStale())->toBeTrue();
        Cache::put('scheduler:heartbeat', now()->subSeconds(10)->toIso8601String(), 600);
        expect($probes->schedulerStale())->toBeFalse();
    });
});

describe('the failed jobs list', function () {
    it('shows what failed and why in one line, and never the job\'s data', function () {
        ($this->failed)(['class' => RefreshCohortsJob::class]);

        $html = ($this->as)($this->viewer)->get('/admin/ops/failed-jobs')->assertOk()->assertSee('Failed jobs')->assertSee('RefreshCohortsJob')->assertSee('RuntimeException: boom')->assertSee('low')->getContent();
        expect($html)->not->toContain('hunter2')->not->toContain('very/long/trace')->not->toContain('private-data')->not->toContain('01712345678');
    });

    it('lists the newest first, thirty at a time, each exactly once', function () {
        $ids = [];
        foreach (range(1, 70) as $i) {
            $ids[] = ($this->failed)(['error' => "Error number {$i}"]);
        }
        $page = fn (?int $before = null) => ($this->as)($this->viewer)->get('/admin/ops/failed-jobs'.($before ? "?before={$before}" : ''))->assertOk()->getContent();
        preg_match_all('#Error number (\d+)#', $page(), $a);
        preg_match_all('#Error number (\d+)#', $page($ids[40]), $b);
        preg_match_all('#Error number (\d+)#', $page($ids[10]), $c);

        $first = ($this->as)($this->viewer)->get('/admin/ops/failed-jobs')->assertOk()->assertSee('Older entries')->getContent();
        expect($first)->toContain('before='.$ids[40])->and($page($ids[10]))->not->toContain('Older entries');   // a link to the next page, and none on the last
        expect(count($a[1]))->toBe(30)->and($a[1][0])->toBe('70')->and(count($b[1]))->toBe(30)->and($b[1][0])->toBe('40')->and(count($c[1]))->toBe(10)->and($c[1][9])->toBe('1');
        ($this->as)($this->viewer)->get('/admin/ops/failed-jobs?before=abc')->assertSessionHasErrors('before');
    });

    it('says so when nothing has failed, and shows an unreadable payload as unknown', function () {
        ($this->as)($this->viewer)->get('/admin/ops/failed-jobs')->assertOk()->assertSee('No failed jobs.');
        ($this->failed)(['payload' => 'not json at all']);
        ($this->failed)(['payload' => json_encode(['displayName' => '', 'data' => []])]);   // readable, but it names no job
        $html = ($this->as)($this->operator)->get('/admin/ops/failed-jobs')->assertOk()->assertSee('Not safe to repeat')->getContent();

        expect(substr_count($html, 'font-mono">unknown<'))->toBe(2);
    });
});

describe('sending a failed job back', function () {
    beforeEach(function () {
        config(['queue.default' => 'database']);
        $this->post = fn (int $id, string $what, $who = null) => ($this->as)($who ?? $this->operator)->post("/admin/ops/failed-jobs/{$id}/{$what}");
    });

    it('puts a safe job back on the queue once, and records who did it', function () {
        $id = ($this->failed)(['class' => RefreshCohortsJob::class, 'queue' => 'low']);

        ($this->post)($id, 'retry')->assertRedirect('/admin/ops/failed-jobs')->assertSessionHas('status');

        expect(DB::table('failed_jobs')->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(1)->and(DB::table('jobs')->value('queue'))->toBe('low');
        $audit = ($this->audits)('ops.failed_job_retried');
        expect($audit)->toHaveCount(1)->and($audit[0]->actor_id)->toBe($this->operator->id)->and(json_decode($audit[0]->after, true))->toBe(['class' => RefreshCohortsJob::class, 'queue' => 'low']);
    });

    it('does nothing the second time, and nothing for a job that is not there', function () {
        $id = ($this->failed)();
        ($this->post)($id, 'retry');
        ($this->post)($id, 'retry')->assertSessionHasErrors('ops');             // pressed twice, or two people at once
        ($this->post)(999999, 'retry')->assertSessionHasErrors('ops');

        expect(DB::table('jobs')->count())->toBe(1)->and(($this->audits)('ops.failed_job_retried'))->toHaveCount(1);
    });

    it('refuses a job that is not on the safe list, and leaves it where it is', function () {
        foreach ([PollRefundsJob::class, DeliverNotificationJob::class, SendCartRemindersJob::class, 'unknown'] as $class) {
            $id = ($this->failed)(['class' => $class]);
            ($this->post)($id, 'retry')->assertSessionHasErrors('ops');
        }

        expect(DB::table('failed_jobs')->count())->toBe(4)->and(DB::table('jobs')->count())->toBe(0)->and(($this->audits)('ops.failed_job_retried'))->toHaveCount(0);
        expect(fn () => app(RetryFailedJob::class)->handle($this->actor, (int) DB::table('failed_jobs')->value('id')))->toThrow(OpsException::class, 'job_not_retryable');
    });

    it('lists only safe things as safe: nothing that sends messages, moves money or changes an order', function () {
        foreach ((array) config('ops.retryable_jobs') as $class) {
            expect(class_exists($class))->toBeTrue("{$class} does not exist");
            expect($class)->not->toMatch('/Payment|Refund|Notif|Deliver|SendCart|BackInStock|Courier|Shipment|Erasure|Cancel|Booking|Import|BuildReport|BuildExport/');
        }
    });

    it('forgets any kind of job, keeping what it was in the audit log', function () {
        $id = ($this->failed)(['class' => PollRefundsJob::class, 'error' => "RuntimeException: gateway refused (token=abc123)\n#0 trace"]);

        ($this->post)($id, 'forget')->assertRedirect('/admin/ops/failed-jobs')->assertSessionHas('status');

        $audit = ($this->audits)('ops.failed_job_forgotten');
        expect(DB::table('failed_jobs')->count())->toBe(0)->and($audit)->toHaveCount(1)->and(json_decode($audit[0]->before, true))->toBe(['class' => PollRefundsJob::class, 'queue' => 'low', 'error' => 'RuntimeException: gateway refused (token=[redacted]']);   // the redactor takes the bracket with the value: safer, not tidier
        ($this->post)($id, 'forget')->assertSessionHasErrors('ops');
        expect(fn () => app(ForgetFailedJob::class)->handle($this->actor, $id))->toThrow(OpsException::class, 'job_gone');
    });

    it('is closed to those who may only look, and the buttons are not shown to them', function () {
        $id = ($this->failed)();

        ($this->post)($id, 'retry', $this->viewer)->assertForbidden();
        ($this->post)($id, 'forget', $this->viewer)->assertForbidden();
        ($this->post)($id, 'retry', catalogStaff(['system.view']))->assertForbidden();
        expect(DB::table('failed_jobs')->count())->toBe(1);

        ($this->as)($this->viewer)->get('/admin/ops/failed-jobs')->assertOk()->assertDontSee('Send back')->assertDontSee('Forget');
        ($this->as)($this->operator)->get('/admin/ops/failed-jobs')->assertOk()->assertSee('Send back')->assertSee('Forget');
    });
});

describe('who may open the operations screens', function () {
    it('keeps health, scheduler and data checks to system.view and failed jobs to queues.view', function () {
        $systemOnly = catalogStaff(['system.view']);
        $queuesOnly = catalogStaff(['queues.view']);

        foreach (['/admin/ops/health', '/admin/ops/scheduler', '/admin/ops/integrity'] as $url) {
            ($this->as)($systemOnly)->get($url)->assertOk();
            ($this->as)($queuesOnly)->get($url)->assertForbidden();
        }
        ($this->as)($queuesOnly)->get('/admin/ops/failed-jobs')->assertOk();
        ($this->as)($systemOnly)->get('/admin/ops/failed-jobs')->assertForbidden();
        ($this->as)(catalogStaff(['catalog.view']))->get('/admin/ops')->assertForbidden();

        auth('admin')->logout();
        $this->flushSession();
        $this->get('/admin/ops')->assertRedirect();
    });

    it('shows each person only the cards they may open', function () {
        ($this->as)(catalogStaff(['system.view']))->get('/admin/ops')->assertOk()->assertSee('System health')->assertSee('Scheduled tasks')->assertSee('Data checks')->assertDontSee('Background work that did not finish');
        ($this->as)(catalogStaff(['queues.view']))->get('/admin/ops')->assertOk()->assertSee('Background work that did not finish')->assertDontSee('System health');
    });

    it('reads in Bangla', function () {
        $this->operator->forceFill(['locale' => 'bn'])->save();
        $who = $this->operator->fresh();
        ($this->failed)();

        ($this->as)($who)->get('/admin/ops')->assertOk()->assertSee('পরিচালনা');
        ($this->as)($who)->get('/admin/ops/health')->assertOk()->assertSee('সিস্টেমের স্বাস্থ্য');
        ($this->as)($who)->get('/admin/ops/scheduler')->assertOk()->assertSee('নির্ধারিত কাজ');
        ($this->as)($who)->get('/admin/ops/failed-jobs')->assertOk()->assertSee('ব্যর্থ কাজ')->assertSee('ফেরত পাঠান');
        ($this->as)($who)->get('/admin/ops/integrity')->assertOk()->assertSee('তথ্য পরীক্ষা');
    });

    it('keeps the two language files in step', function () {
        $keys = function (array $a, string $prefix = '') use (&$keys): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? array_push($out, ...$keys($v, "{$prefix}{$k}.")) : $out[] = "{$prefix}{$k}";
            }

            return $out;
        };
        $en = $keys(require app_path('Modules/Platform/resources/lang/en/ops.php'));
        $bn = $keys(require app_path('Modules/Platform/resources/lang/bn/ops.php'));
        expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
    });
});
