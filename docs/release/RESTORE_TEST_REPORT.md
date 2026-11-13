# Restore test report — Phase 12 (partial rehearsal)

**Verdict: the logical backup-and-restore *mechanics* work and lose nothing; the production recovery targets (RTO ≤ 4 h, RPO ≤ 15 min) are NOT proven.** What was rehearsed is a dump and restore on a throw-away MariaDB 11.4 container with a small data set. The things that decide the targets (real hosting, real size, binary-log point-in-time recovery, object storage, off-site encrypted copies) cannot be rehearsed from here and remain a launch gate for the person who runs the servers.

## What was done (2026-11-13)

* Source: the seeded browser-test database `velmora_e2e` on MariaDB 11.4.13 (10.8 MB, 189 tables, about 3,000 rows: the demo catalogue, a few dozen orders, tickets, bookings).
* Backup: `mariadb-dump --single-transaction --routines --triggers --events --hex-blob`, compressed.
* Restore: into a brand-new empty database created with `utf8mb4` / `utf8mb4_uca1400_ai_ci`, as the production restore procedure says (never over the damaged one).
* Script: kept with the session notes; steps are in `docs/runbooks/database-failover-restore.md`.

## Results

| Check | Result |
|---|---|
| Dump time / restore time / file | 1.4 s / 5.1 s / 0.1 MB compressed (a tiny data set: **not a basis for RTO**) |
| Tables, indexes | 189 = 189, 949 = 949 |
| Foreign keys, CHECK constraints | 249 = 249, 180 = 180 |
| Triggers | 110 = 110, and they **still enforce**: an UPDATE of the audit log and a DELETE from the stock ledger on the restored copy are refused ("append-only table") |
| Collation | `utf8mb4_uca1400_ai_ci` kept |
| Data | content checksum of all 188 base tables identical, 0 differences |
| Migrations | `migrate:status` on the restored copy: nothing pending |
| Application integrity checks run against the restored copy | all 6 pass: stock ledger, stock reservations, order lines add up, refunds within captured, outbox not stuck, coupon use counts |

## Not rehearsed (and why it matters)

1. **Point-in-time recovery.** Binary logging is off on this development container, so replaying logs to a chosen minute (the RPO ≤ 15 min promise) could not be tried. Needs the production server with `log_bin` on.
2. **Time at real size.** Restore time grows with data. The owner's RTO of 4 hours can only be judged with a restore of a production-sized backup on the real hosting. Phase 10's scale data (550,000 parcels) shows the biggest tables will be the parcel events, orders and the report copies; those are the ones to time.
3. **Object storage** (product images, private digital files): versioning and restore not tried (no production bucket).
4. **Off-site, encrypted backups**, their retention (35 days daily / 12 months monthly are assumptions in the DevOps document) and the monthly automated restore verification: not set up here.
5. **Secrets rotation drill** and keeping `APP_KEY` with the backups (encrypted fields cannot be read without it): described in the runbook, not rehearsed.
6. A restore **into the application on real hosting**, with queues, scheduler and payments reconciliation running afterwards.

## What to do before launch (owner / server administrator)

1. Turn on binary logging and a backup job (daily full + continuous logs), encrypted and copied off the server.
2. Do one timed restore of a production-sized backup to a clean server; do a point-in-time restore to a chosen minute; record both times here.
3. Repeat the checks in the table above (counts, constraints, triggers, checksums, the six integrity checks).
4. Schedule the monthly restore verification and keep this report updated.
