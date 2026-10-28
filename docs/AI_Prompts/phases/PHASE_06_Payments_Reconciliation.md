# Phase 6 — Payments, COD, Refunds & Reconciliation

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | 20–30 person-days (excl. provider onboarding wait) |
| **Prerequisites** | Phase 5; sandbox credentials for chosen gateway(s) |
| **Modules** | Payments, Orders (payment integration, admin lifecycle), Notifications, Reporting (finance basics) |
| **Attach** | `PAYMENT_INTEGRATION_DESIGN.md`, provider docs saved under `docs/vendor/<provider>/` (with access date), `SECURITY_AND_PRIVACY.md` |
| **Exit gate** | Sandbox payments proven correct under duplicate/late/forged/out-of-order callbacks; COD collection & reconciliation works; refunds can't exceed captured |

## Copy-paste prompt

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

OBJECTIVE
Make money movement correct, idempotent, auditable and provider-independent.

RULES (non-negotiable)
- Follow `02_BASELINE_DECISIONS.md` §7. Use ONLY endpoints/fields/signature schemes present in the saved official provider documentation; if a detail is missing, STOP and ask — never guess.
- A payment is "paid" only after server-to-server verification of amount + currency + merchant reference + status. Redirect/return URLs are UX only.
- Credentials live in encrypted settings/env, never in code or logs.

TASKS
1. **Domain:** `payments`, `payment_transactions`, `payment_webhook_events`, `refunds`, `payment_methods` (admin-configurable: label bn/en, provider, fees, min/max, enabled zones, enabled product types), `cod_collections`, `cod_remittances`.
2. **Contract:** `PaymentGateway` interface (initiate, verify, parseCallback, refund, status, capabilities) + `GatewayRegistry`. Provider implementations behind it: first the chosen aggregator (sandbox), then (if in scope) bKash/Nagad direct. Each declares `capabilities()` (supports refund? partial refund? tokenised? status query?) — verified, not assumed.
3. **Flow:** `InitiatePayment` Action → create payment + transaction (`initiated`) → call provider → persist provider reference → redirect. Return endpoint: show "verifying…" and call `VerifyPayment` (server-to-server) — never mark paid from query params. Callback/IPN endpoint: store raw event (unique provider+event id) → authenticate per provider docs → process in a queue job under `FOR UPDATE` → idempotent transition → emit `PaymentSucceeded|Failed` via outbox → Orders listener updates `payment_status`/`status`.
4. **Edge cases implemented & tested:** duplicate callbacks; callback before browser return; browser return before callback; late success after expiry (→ flagged `needs_review` or auto-refund per ADR, never silently lost); amount/currency mismatch (→ reject + alert); out-of-order events; provider timeout (→ pending + reconciliation); user retries a failed payment (new transaction, same payment); parallel payment attempts on one order (only one may succeed; extras auto-refund/flag).
5. **Expiry:** online payments expire (config); release stock via `ExpireUnpaidOrders` job and allow re-open if stock available.
6. **Reconciliation:** scheduled `ReconcilePendingPayments` (calls provider `status()`), `DailySettlementImport` (CSV/API per provider if supported) matching provider ↔ internal ledger, exceptions screen (unmatched, amount mismatch, orphan) with resolve actions and audit.
7. **Refunds:** request → approve (permission `payments.refund`, optional two-person rule above threshold) → execute via provider if capability exists else manual-refund record with proof reference. Guard: `Σ(refunds in requested|processing|succeeded) + new ≤ captured` under lock. Full + partial; item-level refunds optionally restock via Phase 7.
8. **COD:** collection recorded when courier reports delivery (Phase 7) or manually; `cod_remittances` per courier settlement; mismatch handling; aging report of uncollected/unremitted COD.
9. **Admin:** payments list/detail with raw event viewer (redacted), manual verify button, refund UI, reconciliation dashboard, payment-method settings, provider health.
10. **Observability:** metrics + alerts: callback failure rate, pending > N min, mismatch count, refund failures, webhook signature failures.

DATABASE: constraints `UNIQUE(provider, provider_txn_id)`, `UNIQUE(provider, provider_event_id)`, CHECK amounts > 0, partial index on `status='pending'`.
SECURITY: webhook authenticity per provider (signature/IP allow-list/server-side verification call as documented); replay window; constant-time compares; raw payload retention policy; PII redaction in logs; admin refund approval audit.

TESTS (must pass)
- Table-driven replay suite using recorded sandbox fixtures: duplicate, reordered, delayed, forged (bad signature), wrong amount, wrong currency, unknown reference.
- Parallel callbacks for the same payment (multiple processes) → one transition, one `PaymentSucceeded`.
- Crash between commit and event dispatch → outbox delivers exactly-once-effect.
- Refund race: two simultaneous refund requests whose sum exceeds captured → one rejected.
- Success redirect without verified callback → order stays unpaid.
- COD: collected → remitted → reconciled; short-remittance flagged.
- Payment expiry releases stock; late success handled per ADR.
- Optional opt-in sandbox suite (not run in CI by default) hitting real provider sandbox.

ACCEPTANCE CRITERIA
- Zero cases in the replay suite result in double-paid, wrongly-paid or lost payments.
- Finance can answer "what did we collect, refund, and still owe from couriers/gateways today?" from the admin without SQL.
- No live credentials used; go-live switch documented in DEPLOYMENT_AND_DEVOPS (Phase 13).

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
