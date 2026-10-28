# Phase 7 — Fulfilment, Couriers & Returns

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | 15–22 person-days |
| **Prerequisites** | Phase 6; courier sandbox/API access |
| **Modules** | Shipping (shipments/couriers), Inventory (ops), Orders (fulfilment axis), Payments (COD hooks) |
| **Attach** | `SHIPPING_AND_FULFILLMENT_DESIGN.md`, saved courier API docs in `docs/vendor/<courier>/` |
| **Exit gate** | Staff can pick-pack-ship with a courier (or manually), see tracking sync, handle failed delivery and returns, and stock/COD reflect reality |

## Copy-paste prompt

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

OBJECTIVE
Operational fulfilment that matches how Bangladeshi e-commerce actually runs: COD, couriers, failed deliveries, returns.

TASKS
1. **Fulfilment workflow:** order → pick list → pack → create shipment(s) (full or partial) → handover → in transit → delivered/failed/returned. `shipments`, `shipment_items`, `shipment_events` (append-only), packing slips and invoices (PDF, bn+en, embedded Bangla font; 🔎 VAT invoice format per verified NBR rules).
2. **Courier contract** `CourierProvider` (createShipment, cancel, track, parseWebhook, rateQuote?, codSettlement?) + `ManualCourier` implementation (staff enter tracking id/status) + first real provider from saved docs. Capability flags per provider. Credentials in `courier_accounts` (encrypted).
3. **Sync:** webhook endpoint (authenticated per docs) + polling fallback job; out-of-order/duplicate event handling via event timestamps & idempotent upserts; map provider statuses → internal statuses (mapping table documented, unknown status → `unknown` + alert, never crash).
4. **Delivery outcomes:** delivered (→ COD collected event, order fulfilment axis updated, digital/service unaffected), failed attempt (reason, retry count, customer notification), return-to-origin, lost/damaged.
5. **Returns/RMA (operational side):** return authorization, reverse shipment (or drop-off), receive-back with inspection outcome (restock / quarantine / write-off) → stock movements; hooks to refunds (Phase 6) — refund amount suggestion, never auto-execute.
6. **Inventory ops:** multi-location transfers, stock-take (cycle count with variance adjustments + approval), low-stock and aging reports, purchase orders & suppliers **only if** confirmed in discovery (else keep as a backlog epic).
7. **Courier reconciliation:** compare courier delivery/COD statements to internal records; exceptions screen; link to `cod_remittances` (Phase 6).
8. **Customer tracking page:** timeline in bn/en using internal statuses; courier tracking link; no leakage of other customers' data.
9. **Admin:** shipment list/detail, bulk create shipments, bulk label print, courier settings, status-mapping view, exception queue.

DATABASE: shipments, shipment_items, shipment_events, courier_accounts, return_requests, return_items, stock_transfers, stock_counts. `UNIQUE(courier, courier_consignment_id)`.
SECURITY: webhook auth; no public tracking by sequential id; signed tracking links; PII minimisation to courier payloads.

TESTS (must pass)
- Partial shipment: stock committed only for shipped lines; order remains `partially_fulfilled`.
- Cancel-vs-ship race (parallel) → exactly one wins, state consistent, stock correct.
- Courier events out of order/duplicated → final state correct and history append-only.
- Unknown provider status → logged/alerted, order unaffected.
- Delivery triggers COD collected exactly once.
- Return received → restock vs quarantine paths adjust stock correctly; refund not auto-executed.
- Stock-take variance requires approval and writes ledger rows.

ACCEPTANCE CRITERIA
- With courier API down, staff can continue with ManualCourier without data loss.
- Every unit of stock is traceable from receipt → reservation → shipment → return via the ledger.

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
