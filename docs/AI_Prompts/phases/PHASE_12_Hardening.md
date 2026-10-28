# Phase 12 — Hardening & Pre-Production

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | 12–18 person-days (+ external pen-test lead time) |
| **Prerequisites** | Phases 1–11 feature complete (or MVP subset) |
| **Attach** | Whole `docs/architecture/`, `TESTING_AND_QA_STRATEGY.md`, `SECURITY_AND_PRIVACY.md`, `PERFORMANCE_AND_SCALABILITY.md` |
| **Exit gate** | Written go/no-go report with evidence; all P0/P1 defects closed; restore test passed |

## Copy-paste prompt

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

OBJECTIVE
Prove the system is safe, fast, resilient and operable **before** real customers and real money touch it. You are the sceptical reviewer; do not assume earlier phases are correct.

TASKS
1. **Full regression & E2E:** Playwright journeys in both locales on mobile + desktop viewports: browse→search→cart→COD checkout; prepaid checkout (sandbox); digital purchase→download; service booking→reminder; cancel; return→refund; admin fulfil→courier(sandbox)→deliver→COD reconcile.
2. **Security review:** run a structured review (OWASP Top 10 + ASVS L2 mapping) across auth, IDOR, mass assignment, SSRF (webhooks, image fetch), XSS (bn/en content, CMS, reviews), CSRF, file upload/download, injection, rate limits, session/MFA, secrets in repo/history, dependency audit, headers/CSP enforcement (move report-only → enforce), logging redaction, admin privilege boundaries. Produce findings with severity, fix, retest. Arrange independent penetration test (human) and track results.
3. **Payments hardening drill:** replay suite re-run; chaos tests (gateway timeout, partial outage, callback storms, clock skew); verify alerting actually fires.
4. **Concurrency & integrity audit:** re-run all concurrency tests under load; run data-integrity jobs on a production-sized dataset (stock ledger, payment↔order, order totals, orphan files).
5. **Performance:** k6 scenarios (browse, search, checkout, webhook burst, admin reports) against production-like infra; compare with targets in `02 §14`; profile slow queries (`pg_stat_statements`), fix N+1 and missing indexes; verify cache behaviour and invalidation; test degraded mode (Redis down, search slow, gateway down).
6. **Accessibility:** WCAG 2.2 AA audit (automated + manual keyboard/screen-reader pass) on storefront and admin core flows; Bangla text rendering on low-end Android browsers.
7. **Backup & restore:** full restore rehearsal from backups to a clean environment (PITR to a chosen timestamp); measure RTO/RPO against targets; object-storage restore; secrets rotation drill.
8. **Operational readiness:** runbooks (payment stuck, courier outage, stock mismatch, SMS provider failure, queue backlog, DB failover, rollback), on-call/alert routing, log retention, dashboards, status page plan, support SOPs (COD confirmation script, refund approvals).
9. **Content & legal readiness:** policies reviewed by counsel, cookie/consent behaviour, invoice format reviewed by tax adviser, seller disclosures present.
10. **Go/No-go report:** checklist with evidence links, residual risks, owner sign-offs.

DELIVERABLES: `docs/release/PRODUCTION_READINESS_REPORT.md`, security findings register, performance report, restore-test report, runbooks folder.

ACCEPTANCE CRITERIA
- Zero open Critical/High security findings; Mediums have accepted risk or dated fix.
- Performance targets met or formally waived with rationale.
- Restore rehearsal completed within RTO/RPO.
- All ten Draft.md high-risk scenarios + the seven additional scenarios have passing automated tests.

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
