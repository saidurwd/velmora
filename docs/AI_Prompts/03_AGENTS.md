# AGENTS.md — Repository instructions for AI coding agents

> Copy this file to the repo root as `AGENTS.md` (and `CLAUDE.md` containing `@AGENTS.md` if you use Claude Code). Keep it under ~200 lines; link to docs rather than duplicating them. Fill `{{…}}` placeholders in Phase 1.

## Project
Bilingual (en/bn) e-commerce platform for Bangladesh: physical products, digital products, services. Laravel + Blade + PostgreSQL modular monolith, with a versioned REST API for future mobile apps.
Authoritative docs: `docs/architecture/*` (esp. `DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md`, `DATABASE_DESIGN.md`, `ARCHITECTURE_DECISION_RECORDS.md`). If code and docs disagree, **stop and ask**; do not guess.

## Commands (fill in Phase 1)
* Setup: `{{docker compose up -d && composer install && npm ci && php artisan migrate --seed}}`
* Tests: `{{php artisan test --parallel}}` · single: `{{php artisan test --filter=…}}`
* Static analysis: `{{vendor/bin/phpstan analyse}}` · Style: `{{vendor/bin/pint --test}}`
* Architecture rules: `{{vendor/bin/pest --group=arch}}`
* Frontend: `{{npm run build}}` · E2E: `{{npx playwright test}}`

## Workflow for every task
1. **Read first.** Inspect the repo, the relevant ADRs, the module's docs and existing tests. Never overwrite or delete work you do not understand.
2. **State the plan** (files to add/change, tables, events, tests) and the acceptance criteria *before* coding. For anything touching money, stock, auth, or schema: wait for approval of the plan.
3. **Vertical slice:** migration + model + Action + policy + request validation + controller/resource/view + translations (en **and** bn) + tests — together.
4. **Test-first for risky logic** (payments, stock, coupons, refunds, bookings, authorization).
5. Run: format → static analysis → architecture tests → relevant tests → full suite before finishing.
6. **Report honestly:** list commands run and their real results. Never claim a test passed unless you ran it. If something is not done or not verified, say so.
7. Update docs/ADRs/CHANGELOG/OpenAPI in the same change. Small commits, Conventional Commits (`feat(orders): …`).

## Architecture rules (enforced in CI)
* Code lives in `app/Modules/<Module>/…`. Cross-module use is limited to **Contracts, DTOs, Enums, Events**. No cross-module Model/table access.
* Controllers: FormRequest → Policy → **one Action** → Resource/View. No business logic in controllers, Blade, observers or routes.
* Web, Admin and API call the **same Actions**.
* No generic repositories, no service-locator, no static helpers holding state. Prefer constructor injection.
* Events dispatched **after commit**; critical events via the outbox.
* Add a dependency only with a written reason in the PR/ADR (maintenance status, licence, security record).

## Data rules
* **Database is MariaDB/MySQL (not PostgreSQL)** — see `docs/architecture/DATABASE_ENGINE_DECISION.md`; ignore PostgreSQL-specific wording below.
* PostgreSQL only (also in tests). Migrations are forward-only, reversible where practical, and follow **expand → migrate → contract** for live tables.
* Money: `bigint` minor units + `currency`; use the `Money` value object. **Never floats.**
* IDs: internal `bigint`, external `public_id` (ULID). Never expose auto-increment IDs.
* Add FK, `CHECK`, `UNIQUE` and indexes in the migration. State the query each index serves in a comment.
* Financial & inventory tables are **append-only**; no deletes. Catalog/CMS may soft-delete.
* Concurrency-critical writes (stock, payments, coupons, refunds, bookings, status transitions) use the patterns in `DATABASE_DESIGN.md`: conditional `UPDATE`, `SELECT … FOR UPDATE`, unique constraints, exclusion constraints, bounded retry on deadlock. Never read-then-write.

## Localisation rules
* No hard-coded user-visible text in PHP/Blade/JS: use translation keys; add **both** `en` and `bn`. CI fails on missing keys.
* Translatable content = `*_translations` tables. Display via the fallback chain (bn → en).
* Normalise input (NFC, Bangla→ASCII digits for numeric fields). Phone numbers → E.164 via a single `PhoneNumber` class.
* Dates/numbers/currency via the locale formatter only. Test every new view in both locales.

## Security rules
* Validate all input in FormRequests; authorize with Policies/permissions on every route (deny by default); check ownership for every customer-scoped resource.
* Never log secrets, OTPs, tokens, full phone numbers, or payment payloads unredacted.
* Secrets only via environment/secret manager; never in code, fixtures, tests, or docs. Provider credentials stored encrypted.
* Payment success is accepted **only** after server-to-server verification (amount + currency + reference + status). Never trust redirect parameters.
* File uploads: type/size allow-list, re-encode images, store outside web root; digital goods only in the private bucket, delivered via authorised, short-lived URLs.
* Rate-limit auth, OTP, search, checkout and webhook endpoints.

## Testing rules
* New behaviour ⇒ tests. Bug fix ⇒ failing regression test first.
* Required for money/stock/auth changes: concurrency or replay test where relevant (parallel requests for the last unit; duplicate callback; double refund; coupon limit; double booking; cross-customer access).
* Don't weaken, skip, or delete tests/validation/authorization to get green. If a test is wrong, explain why before changing it.
* External APIs are faked via contracts + recorded fixtures; sandbox calls only in a separate, opt-in suite. **Never hit live payment/courier/SMS in tests.**

## Definition of Done (per task)
Acceptance criteria met · tests (unit+feature, + concurrency/security where applicable) pass · static analysis & style clean · architecture tests pass · en+bn strings present · authorization verified · no N+1 (query-count assertion on list pages) · migrations tested up on a copy of realistic data · docs/ADR/OpenAPI/CHANGELOG updated · no secrets · honest report delivered.

## Ask a human before
Irreversible data operations · destructive migrations · production deployment · any live-payment/live-courier/live-SMS action · changing a pinned decision (ADR) · adding a major dependency · deleting a module or table · anything that conflicts with docs.

## Anti-patterns to avoid
Premature microservices · speculative features · duplicated pricing/tax/stock logic across web/API · logic in Eloquent observers · float money · status strings scattered in code (use enums + state machine) · "temporary" bypass of auth · silent catch blocks · mass-assignment of request input.
