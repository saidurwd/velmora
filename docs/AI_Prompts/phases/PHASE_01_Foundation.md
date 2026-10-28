# Phase 1 — Architecture Approval & Project Foundation

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | Architecture docs: 8–12 days (master prompt Batches 2–7, includes your review time) · Scaffold: 6–10 days |
| **Prerequisites** | Phase 0 signed off |
| **Attach** | Entire `docs/architecture/`, `02_BASELINE_DECISIONS.md`, `03_AGENTS.md` |
| **Exit gate** | Empty-but-production-shaped app boots in Docker, CI green, arch tests enforce module rules, ADRs merged |

Part A = finish the architecture package (master prompt Batches 2–7). Part B = the scaffold prompt below (first code).

## Copy-paste prompt (Part B — scaffold)

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

OBJECTIVE
Create the project foundation so that every later phase only adds vertical slices. No business features yet.

SCOPE
1. Laravel (version per ADR-001), PHP, PostgreSQL, Redis, MinIO, Mailpit via Docker Compose; `.env.example` with every variable documented; no secrets committed.
2. Module skeleton generator (artisan command or script) that creates `app/Modules/<Name>/…` per BD-10, registers its service provider, routes, views, lang and migrations paths.
3. **Platform module (shared kernel):**
   - `Money` value object (bigint minor units, currency, add/sub/multiply/allocate without losing paisa, formatter per locale) with exhaustive unit tests.
   - `Clock`, `RequestContext` (request id, actor, locale), `Settings` (typed key/value with cache), `AuditLogger` + `audit_events` table, `IdempotencyKey` store + middleware, `Outbox` table + dispatcher job + `afterCommit` event bridge, `PublicId` (ULID) trait, `HasTranslations` trait for `*_translations` tables, `PhoneNumber` (E.164 + BD rules + Bangla digit normalisation), `BanglaText` normaliser (NFC, digits).
   - Rule: **Actions never read session/request/auth globals** — they take DTOs and an explicit `Actor`. This keeps Web, Admin and API identical.
4. Global exception handling: web error pages (en/bn), API problem+json mapper with stable error codes catalogue, correlation id in logs and responses.
5. Structured JSON logging with redaction processor (phones, OTPs, tokens, card-like numbers, provider secrets).
6. Quality tooling: Pint, Larastan (level per ADR), Pest, **architecture tests** implementing BD-11 rules, translation-key parity test, "no float in money columns" migration test, "no hard-coded text in views" test.
7. CI (GitHub Actions): composer/npm cache, Postgres+Redis services, run style → static analysis → arch → tests → build assets; dependency audit (`composer audit`, `npm audit`); secret scanning.
8. Base Blade layouts (storefront + admin shells), Vite/Tailwind config with design tokens from WEB_UI_UX_GUIDELINES, self-hosted Bangla font, `<html lang>` handling. Placeholder pages only.
9. Health endpoints (`/healthz` liveness, `/readyz` readiness: DB, Redis, storage), Horizon, scheduler heartbeat.
10. Docs: README quick start, `CONTRIBUTING.md`, ADR template, CHANGELOG, PR template with the Definition-of-Done checklist, final `AGENTS.md` with real commands.
11. Generate `docs/backlog/BACKLOG.md` if not already produced.

DATABASE: only Platform tables (`audit_events`, `idempotency_keys`, `outbox_messages`, `settings`, `jobs`/`failed_jobs`, `languages`).
SECURITY: secure headers middleware (CSP report-only first), trusted proxies, HTTPS-only cookies in non-local, session hardening, debug off outside local, rate-limiter definitions registered.

TESTS (must exist and pass)
- Money: rounding/allocation properties (sum of allocated parts == total), overflow, currency mismatch exception, formatting en/bn.
- PhoneNumber: valid/invalid BD formats (01XXXXXXXXX, +8801XXXXXXXXX, 8801XXXXXXXXX, Bangla digits), non-BD numbers.
- Outbox: event survives process crash between commit and dispatch (simulate), dispatched exactly once logically (consumer idempotent).
- Idempotency middleware: same key+same body → same response; same key+different body → 422/409.
- Arch tests: a deliberately violating fixture module fails the rule.
- Log redaction test.

ACCEPTANCE CRITERIA
- `docker compose up` + one documented command → app, queue worker, scheduler, mail catcher running.
- CI green on a clean clone; failing arch/translation/style check blocks merge.
- New module can be generated in < 1 minute and passes arch tests.
- No dependency added without an entry in ADR/README rationale.

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
