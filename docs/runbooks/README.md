# Runbooks

One page per thing that goes wrong. Each says what you see, what to check, what to do, how to know it worked, and when to ask for help. They are written **from the code, the screens and the architecture documents**; none has been rehearsed against a real production environment yet (that is part of the launch rehearsal in `docs/release/PRODUCTION_READINESS_REPORT.md`). Where a step depends on a hosting detail we do not have yet, it says so.

| Runbook | When |
|---|---|
| [payment-stuck.md](payment-stuck.md) | a customer paid but the order says unpaid, or payments stay "pending" |
| [gateway-outage.md](gateway-outage.md) | the payment service is down or failing |
| [courier-outage.md](courier-outage.md) | parcels cannot be booked or tracked |
| [stock-mismatch.md](stock-mismatch.md) | the shop shows stock that is wrong, or an alert says the ledger does not add up |
| [sms-failure.md](sms-failure.md) | texts (codes, order messages) are not arriving |
| [queue-backlog.md](queue-backlog.md) | things happen late: messages, emails, order steps |
| [database-failover-restore.md](database-failover-restore.md) | the database is down, damaged, or must be brought back |
| [rollback.md](rollback.md) | a release went wrong |
| [secret-leak.md](secret-leak.md) | a password, key, token or credential may have been seen by someone it should not |
| [security-finding-triage.md](security-finding-triage.md) | a security report or penetration-test finding arrives |
| [cod-fraud-spike.md](cod-fraud-spike.md) | many suspicious cash-on-delivery orders |

## The five screens that tell you most

* **System health** `/admin/ops/health` (queue, failed jobs, outbox) · **Scheduled tasks** `/admin/ops/scheduler` (is the scheduler alive; last run of each task) · **Failed jobs** `/admin/ops/failed-jobs` · **Integrity checks** `/admin/ops/integrity`.
* **Money today** `/admin/payments/today` and **Payment settings** `/admin/payments/settings` (payment alerts as a banner).
* **Delivery list** `/admin/notifications/logs` (every text and email: sent, waiting, failed, gave up).
* The log line **`payments.alert`** (signal, value, threshold) is the one to alert on, and the scheduler heartbeat (`scheduler:heartbeat`, every minute).

## Rules that apply to every runbook

* Write down what you did and when (a note on the order or ticket, or the incident log). The audit log records staff actions automatically.
* Never paste a password, token, key, full phone number or payment detail into chat, a ticket or a log.
* Before anything irreversible (deleting data, restoring a backup, changing live payment settings), get a second person to read the step.
* If a runbook step says "ask a developer" and none is reachable, **pause online payments** (Payment settings) and put the site in **maintenance** (`/admin/maintenance`; staff screens stay reachable) rather than guess.
