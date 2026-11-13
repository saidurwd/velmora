# The database is down, damaged, or must be restored

Targets (from `DEPLOYMENT_AND_DEVOPS.md`): **RTO ≤ 4 hours, RPO ≤ 15 minutes**, with daily full backups plus continuous binary logs, encrypted and off-site. **These targets are not yet proven**: a timed restore rehearsal on the real hosting is a launch gate.

**Down but not damaged** (server crashed, failover):
1. Put the site in maintenance mode if it is half-working (`/admin/maintenance` or `php artisan down`; the staff area stays reachable).
2. Bring the database back (or promote the replica). Check `/readyz`.
3. `php artisan queue:restart`; watch *System health*; wait for the scheduler's next minute.
4. Run **Integrity checks** and look at *Money today* for payments that arrived during the gap (the gateway keeps retrying its notifications and `payments:reconcile-pending` asks for the rest).

**Damaged or lost data** (restore):
1. **Stop writes**: maintenance mode, and **pause online payments**.
2. Decide the restore point with a second person (the last good time, within the binary-log range). Restoring loses everything after it: write down the time.
3. Restore the last full backup to a **clean** server, replay binary logs to the chosen time (point-in-time recovery). Never restore over the damaged database; keep it for evidence.
4. Point the app at the restored database. Run `php artisan migrate:status` (everything should be applied; **never** run `migrate:fresh` or `db:wipe`).
5. Rebuild the copies: `php artisan catalog:reindex`, `php artisan search:reindex`, `php artisan reports:rebuild` (for the missing days).
6. Run **Integrity checks** (stock ledger, payments against orders, order totals). Anything red: stop and ask a developer.
7. Reconcile payments for the gap (gateway dashboard against *Reconciliation*), and tell customers whose orders fall after the restore point (use the gateway and courier records to rebuild what you can).
8. Resume payments, then leave maintenance.

Also restore the **object storage** (product images, private digital files) from its versioning, and keep the **APP_KEY** that matches the backup: encrypted fields (saved credentials, push tokens) cannot be read without it.
