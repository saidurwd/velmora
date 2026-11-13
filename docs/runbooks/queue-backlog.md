# Things happen late (queue or scheduler stopped)

**You see:** order messages and emails late; *System health* shows many waiting jobs or an old "oldest" time; *Scheduled tasks* shows tasks not run for minutes; the heartbeat alert fires.

Two moving parts: the **queue worker** (`php artisan queue:work`, kept alive by the process manager) and the **scheduler** (one cron line running `schedule:run` every minute).

1. **Scheduler first**: *Scheduled tasks* (`/admin/ops/scheduler`) shows each task's last run. All stale → the cron line is not running (server restarted? cron disabled?). Fix the cron; the missed minutes are not replayed, but every task is built to catch up on its next run.
2. **Queue**: *System health* → waiting jobs, oldest age. If the worker is not running, start it through the process manager. After a code deploy always `php artisan queue:restart` so workers load the new code.
3. A job failing again and again: *Failed jobs* (`/admin/ops/failed-jobs`) shows the class and a short error. Fix the cause before **Retry**; **Forget** only when the work is no longer wanted (it asks first).
4. Outbox: `outbox:dispatch` runs every minute and delivers recorded events; a growing "pending outbox" with a healthy worker means a handler keeps failing: read the failed jobs.
5. A backlog drains by itself once workers run; for a very large one, start extra workers temporarily.

**Done when:** waiting jobs fall to near zero, the oldest age is under a minute, every scheduled task shows a recent run.
**Note:** Horizon (the queue dashboard) is deliberately available only in local development; use the screens above.
