# Phase 13 (production launch): what is ready, what I can prepare, what only people can do

Phase 13's brief says its prerequisite is **a Phase 12 go decision**, and that anything touching production, live credentials, DNS or real money is **executed by a human**. Today's decision is NO-GO (see `PRODUCTION_READINESS_REPORT.md`), so nothing here should be executed yet. This page is the plan, so the work can start the day staging and a go decision exist.

## Phase 13 tasks against today's state

| # | Task | Already in the repository | I can prepare (no production access) | Needs a person / environment |
|---|---|---|---|---|
| 1 | Infrastructure as code and deploy pipeline | CI (style, static analysis, architecture, full tests, browser tests, Lighthouse, API contract); expand-migrate-contract migration rules; `/healthz`, `/readyz`; boot refuses unsafe production settings (debug on, no key, insecure cookies) | a deploy script (build once, drain queues, migrate, health-gated switch, instant rollback by release folder), a boot check that also fails fast on a missing payment / SMS / push secret when those drivers are switched on, a staging compose/Ansible description | the hosting choice (none made yet), the servers, DNS |
| 2 | Secrets and access | secrets only from the environment or encrypted storage; credentials never logged; rotation steps in `docs/runbooks/secret-leak.md` | least-privilege database roles as SQL (app / migration / read-only reporting), a rotation checklist | the secret manager, SSH and MFA policy, creating the roles |
| 3 | Observability | structured logs, `payments.alert`, scheduler heartbeat, health screens | an alert catalogue (signal, threshold, who is told), uptime-check list, dashboard definitions | error tracking and metrics services, alert routing, on-call rota |
| 4 | Backups | restore mechanics proved (`RESTORE_TEST_REPORT.md`) | a backup job script (full + binary logs, encrypted, off-site), a monthly restore-verification job built on the rehearsal script | binary logging on, storage, keys, the first timed restore |
| 5 | Live-provider cutover | `DEPLOYMENT_AND_DEVOPS.md` §5.2; the live gateway is unreachable unless production **and** sandbox off **and** `PAYMENTS_ALLOW_LIVE`; payment-freeze switch on *Payment settings* | a per-provider cutover script (what to set, in which order, how to roll back to cash-only) | live credentials, IP allow-lists, the **first real payment and refund (human-executed)** |
| 6 | Rollout strategy | feature flags screen; cart-recovery switch; per-method switches | stage definitions with success metrics and stop conditions | the decision to open each stage |
| 7 | Support readiness | support tickets, canned-reply store in the help centre, SOP-shaped runbooks | canned replies and a COD-confirmation script (English and Bangla) | staffing, shifts, a fraud-review shift |
| 8 | Rollback and incident plan | `rollback.md`, `database-failover-restore.md`, `gateway-outage.md` | decision tree and customer message templates | naming the decision owner |
| 9 | Launch checklist and hypercare | `OWNER_LAUNCH_CHECKLIST.md` | a printable launch checklist and a 14-day hypercare schedule (twice-daily dashboard review, daily payment and cash reconciliation, defect triage times) | doing it |

## Acceptance criteria and who can prove them

* **Rehearsal deploy and rollback within the agreed time**: needs staging. I can write and dry-run the scripts locally against a throw-away folder and database.
* **Payment freeze from admin in under 2 minutes**: the switch exists (*Payment settings → Pause online payments*, tested). It still has to be timed by a person on staging.
* **Live smoke test with a minimal real amount, including a refund**: human-executed only, by the owner's authority.

## What I recommend I do next if you say go

1. Deploy script, boot-time secret check (with tests), and the least-privilege SQL roles.
2. Backup job and the monthly restore-verification job (built on the rehearsal that already passed).
3. Alert catalogue, uptime-check list, dashboards definition.
4. Per-provider cutover scripts and the rollout stages, canned replies, launch checklist and hypercare schedule.

None of it touches production, and the one piece of code (boot-time secret check) is a small change with tests. Decisions I need first: **where it will be hosted** (it changes the scripts), and **who the incident decision owner is**.
