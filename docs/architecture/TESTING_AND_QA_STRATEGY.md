# Testing and QA Strategy (Batch 6 draft)

## 1. Principles
Tests run on **real MariaDB** (`velmora_test`, same collation as dev) — never SQLite. Sandbox/fake providers only; no live payment/courier/SMS in automated tests. No weakening/skipping tests to pass; honest reporting. Test DB is wiped by the suite — never point it at `velmora`.

## 2. Pyramid and tooling
| Layer | Tooling | Scope |
|---|---|---|
| Unit | Pest | Money, PhoneNumber, BanglaText, tax calc, state machines, rate engine, policies |
| Feature | Pest + Laravel HTTP | Actions, controllers, authz, validation, events/outbox, queues |
| Database integrity | Pest | CHECK/UNIQUE/trigger/period constraints, append-only, ledger invariants, migration tests |
| Concurrency | Pest + parallel processes (`proc_open`/`pcntl`) | stock, coupons, payments, refunds, bookings, downloads, license keys, idempotency |
| API | Pest + OpenAPI validator | contract, IDOR, parity with web, idempotency storm |
| Browser/E2E | Playwright (en+bn, mobile+desktop) | main journeys |
| Accessibility | axe (Playwright) + manual | key templates both locales |
| Localisation | Pest | key parity, no hard-coded text, formatting, fallback |
| Static | Larastan (≥ level 8), Pint, Pest arch / Deptrac | module rules |
| Performance | k6, Lighthouse CI | targets |
| Security | `composer/npm audit`, secret scan, ZAP baseline, human pen-test (Phase 12) | |
| Visual | Playwright snapshots en/bn | Bangla rendering |

## 3. Mandatory high-risk scenarios (each maps to a mechanism and a test)
| # | Scenario | Mechanism | Test |
|---|---|---|---|
| 1 | Two customers buy last unit | conditional UPDATE + CHECK | N parallel PlaceOrder → 1 order |
| 2 | Duplicate payment callbacks | UNIQUE event + FOR UPDATE + idempotent transition | parallel/replayed IPN → one PaymentSucceeded |
| 3 | Payment success then app timeout | reconciliation + outbox | kill after commit → order paid via status() |
| 4 | Failed payment then retry | new transaction same payment | retry flow |
| 5 | Cancel races fulfilment | FOR UPDATE on order | parallel cancel/ship → one wins |
| 6 | Refund over captured | refund guard under lock | parallel refunds |
| 7 | Coupon beyond limit | conditional UPDATE | 10 sessions, limit 1 |
| 8 | Access another customer's order | scoped query + Policy | data-driven IDOR |
| 9 | Download without entitlement | atomic entitlement check | denied+logged |
| 10 | Booking slot conflict | UNIQUE … WITHOUT OVERLAPS | 20 parallel → 1 |
| 11 | Callback arrives before browser return | converge on verified transition | ordering test |
| 12 | Late callback after expiry | `needs_review` | expiry then success |
| 13 | Amount/currency mismatch | compare to payment row | rejected+alert |
| 14 | Out-of-order courier webhooks | timestamp-ordered events | shuffled fixtures |
| 15 | OTP brute force / SMS pumping | attempt caps + rate limits | lockout + caps |
| 16 | Bangla digit input | normaliser | phone/qty/OTP/price in ০–৯ |
| 17 | Missing translation fallback | bn→en chain | rendering test |
Plus: tax rounding properties (parts sum to total, inclusive/exclusive), order-total CHECK, ledger `on_hand = Σ movements`, outbox exactly-once-effect, transaction rollback leaves no reservation/coupon/order, deadlock-retry.

## 4. CI gates (GitHub Actions)
PR must pass: Pint → Larastan → arch tests → translation parity → no-float-money → no-hardcoded-text → unit/feature (parallel) → DB/concurrency suite → API contract + OpenAPI diff → asset build → dependency audit + secret scan. Nightly: full E2E (en/bn), axe, Lighthouse, k6 smoke. Coverage policy: no global % target; **100 % of branches in Money, state machines, payment/refund/stock/coupon Actions**, and ≥ 80 % line coverage elsewhere in Actions [RECOMMENDATION].

## 5. Test data
Factories per module; deterministic seeds; demo catalog (≥ 40 products, bn+en); recorded provider fixtures under `tests/Fixtures/<provider>/` sanitised; synthetic scale dataset generator (50 k products / 1 M orders) outside CI default.

## 6. Environments
local (Docker) → CI (ephemeral DB) → staging (prod-like, sandbox providers) → production. Live payment smoke tests only by a human with owner authorisation.

## 7. Definition of Done per phase
All acceptance criteria met, listed scenarios automated and passing, static analysis/arch clean, en+bn present, authz verified, no N+1 on list pages, docs/ADR/OpenAPI/CHANGELOG updated, honest report delivered.
