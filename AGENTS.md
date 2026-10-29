# AGENTS.md — Repository instructions for AI coding agents

## Project
Velmora: bilingual (en/bn, **English default**) e-commerce platform for Bangladesh: physical products, digital products, services. Laravel + Blade + **MariaDB** modular monolith, with a versioned REST API for future mobile apps.
Authoritative docs: `docs/architecture/*` (esp. `ARCHITECTURE_DECISION_RECORDS.md`, `DATABASE_ENGINE_DECISION.md`, `DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md`, `DATABASE_DESIGN.md`), `docs/spikes/*`, backlog `docs/backlog/BACKLOG.md`. `docs/AI_Prompts/phases/*` hold per-phase tasks but their PostgreSQL wording is **void** (ADR-000). If code and docs disagree, **stop and ask**; do not guess.

## Commands
* Setup: `docker compose up -d && composer install && npm ci && cp .env.example .env && php artisan key:generate && php artisan migrate --seed` (see README)
* **Full gate: `composer check`** (Pint → Larastan 8 → all tests). Tests: `php artisan test` · one suite `--testsuite=Architecture|Database|Feature|Unit` · filter `--filter=Name`
* Static analysis: `composer analyse` · Style: `vendor/bin/pint` (fix) / `composer lint`
* New module: `php artisan make:module Name`
* Frontend: `npm run build` / `npm run dev` · Browser tests (Playwright + axe): `npm run e2e:prepare` (builds the throw-away `velmora_e2e` database and `.env.e2e`) then `npm run e2e`; they never touch `velmora` or `velmora_test`. Re-run `npm run e2e:prepare` before every `npm run e2e`: the checkout tests place real orders that hold demo stock, so a second run on the same database runs out of stock
* Tool output is compact JSON (laravel/pao); that is expected.
* **Never** run `migrate:fresh`, `db:wipe` or `--env=testing` against the dev database `velmora`; tests use `velmora_test` only.

## Workflow for every task
0. **After every push, check CI** (`gh run list --limit 3`, then `gh run view <id> --log-failed`). A green local run is not proof: the local test DB persists between runs and hides isolation bugs. To reproduce CI, recreate `velmora_test` empty and run `php artisan test --testsuite=Architecture` then `php artisan test`. Unit tests must not touch the DB (put DB-dependent tests under Feature).
1. **Read first.** Inspect the repo, the relevant ADRs, the module's docs and existing tests. Never overwrite or delete work you do not understand.
2. **State the plan** (files to add/change, tables, events, tests) and the acceptance criteria *before* coding. For anything touching money, stock, auth, or schema: wait for approval of the plan.
3. **Vertical slice:** migration + model + Action + policy + request validation + controller/resource/view + translations (en **and** bn) + tests — together.
4. **Test-first for risky logic** (payments, stock, coupons, refunds, bookings, authorization).
5. Run: format → static analysis → architecture tests → relevant tests → full suite before finishing.
6. **Report honestly:** list commands run and their real results. Never claim a test passed unless you ran it. If something is not done or not verified, say so.
7. Update docs/ADRs/CHANGELOG/OpenAPI in the same change. Small commits, Conventional Commits (`feat(orders): …`).

## Architecture rules (enforced in CI)
* Code lives in `app/Modules/<Module>/…` (migrations/seeders in `Database/migrations` and `Database/Seeders` — capital D so PSR-4 works on Linux). Cross-module use is limited to **Contracts, DTOs, Enums, Events**. No cross-module Model/table access.
* Controllers: FormRequest → Policy → **one Action** → Resource/View. No business logic in controllers, Blade, observers or routes.
* Web, Admin and API call the **same Actions**.
* No generic repositories, no service-locator, no static helpers holding state. Prefer constructor injection.
* Events dispatched **after commit**; critical events via the outbox.
* Add a dependency only with a written reason in the PR/ADR (maintenance status, licence, security record).

## Data rules
* **MariaDB ≥ 11.4 (dev 12.1.2), InnoDB, utf8mb4 / `utf8mb4_uca1400_ai_ci`.** Dev DB `velmora`; tests use `velmora_test` **which the suite wipes** — never point tests at `velmora`, never run destructive SQL on `velmora` without approval. No SQLite, no PostgreSQL-only SQL.
* Write transactions run `READ COMMITTED`; DDL auto-commits, so one purpose per migration; times are UTC `DATETIME(6)`, connection tz `+00:00`; enums = VARCHAR + CHECK; hierarchies = closure tables; booking overlap = `UNIQUE(active_resource_id, period WITHOUT OVERLAPS)`.
* Migrations: forward-only, reversible where practical, and follow **expand → migrate → contract** for live tables.
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

## Money & tax
* Tax is a configurable engine (`tax.enabled`, `tax.prices_include_tax`, dated `tax_rates`); owner is not VAT-registered yet, so default `tax.enabled=false`. Rates/Mushak layouts are **never hard-coded** and need tax-adviser confirmation.
* Payments: SSLCommerz (+bKash via it) and COD at MVP; SMS: SSL Wireless ISMS Plus. Use only endpoints present in saved official docs under `docs/vendor/<provider>/`; if missing, STOP and ask.

## Ask a human before
Irreversible data operations · destructive migrations · production deployment · any live-payment/live-courier/live-SMS action · changing a pinned decision (ADR) · adding a major dependency · deleting a module or table · anything that conflicts with docs.

## Anti-patterns to avoid
Premature microservices · speculative features · duplicated pricing/tax/stock logic across web/API · logic in Eloquent observers · float money · status strings scattered in code (use enums + state machine) · "temporary" bypass of auth · silent catch blocks · mass-assignment of request input.
