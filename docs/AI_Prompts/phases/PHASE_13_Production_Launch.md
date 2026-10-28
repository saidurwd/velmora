# Phase 13 — Production Launch

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | 6–10 person-days |
| **Prerequisites** | Phase 12 go decision; live gateway/courier/SMS accounts approved |
| **Attach** | `DEPLOYMENT_AND_DEVOPS.md`, runbooks, production readiness report |
| **Human-only steps** | Live credential entry, DNS cutover, first live payment, legal sign-off. The AI prepares and verifies; **a human executes anything touching production or real money.** |

## Copy-paste prompt

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

OBJECTIVE
Prepare a safe, reversible launch. You may write scripts, configs, checklists and dry-runs; you must NOT deploy to production, enter live credentials, or run live-payment tests yourself. Stop and hand over exact steps for the human operator.

TASKS
1. **Infrastructure as code & deploy pipeline:** reproducible production environment (Terraform/Ansible/compose per ADR), zero-downtime deploy (build once, migrate with expand/contract rules, queue drain, health-gated switch, instant rollback), separate staging that mirrors production, config validation at boot (fail fast on missing secrets).
2. **Secrets & access:** secret manager/vault usage, least-privilege DB roles (app vs migration vs read-only reporting), SSH/MFA policies, key rotation procedure.
3. **Observability:** error tracking, APM/slow-query alerts, structured log shipping with retention, uptime checks (storefront, checkout, webhook endpoints), dashboards (orders/min, payment success rate, queue lag, error budget), alert routing with severity & escalation.
4. **Backups:** automated encrypted DB base backups + WAL archiving (PITR), object-storage versioning, retention schedule, monthly automated restore verification job.
5. **Live-provider cutover plan:** per provider — switch credentials, whitelist callback IPs/URLs, a **controlled live smoke test with minimal real amount authorised by the owner** (including a refund), rollback to "COD only" feature flag.
6. **Rollout strategy:** soft launch (staff + friends) → limited audience (feature flags, invite/coupon) → public; success metrics and stop-conditions for each stage; daily reconciliation during week 1.
7. **Support readiness:** staffing, SOPs, canned replies, escalation, status communications, fraud-review shift.
8. **Rollback & incident plan:** triggers, decision owner, steps (app rollback, DB restore decision tree, payment-freeze switch, customer comms templates).
9. **Launch checklist** (printable) and **hypercare plan** (first 14 days: twice-daily dashboard review, daily payment & COD reconciliation, defect triage SLA).

ACCEPTANCE CRITERIA
- A rehearsal deploy and a rehearsal rollback have both succeeded in staging within the agreed time.
- Payment-freeze (disable online methods, keep COD) can be done from admin in < 2 minutes.
- Live smoke-test results recorded; refund path proven with real minimal amount (human-executed).

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
