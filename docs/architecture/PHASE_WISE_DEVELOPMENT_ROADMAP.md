# Phase-wise Development Roadmap (Batch 7)

Estimates: person-days, 1 senior developer + AI agents (planning ranges, not promises). Phase prompts: `docs/AI_Prompts/phases/PHASE_nn_*.md` (read together with ADR-000). Exit gate = human sign-off.

## 1. Phases
| # | Phase | Days | Prereqs | Key external dependency | Exit gate |
|---|---|---|---|---|---|
| 0 | Discovery | 5–8 | — | gateway/courier/SMS applications | **Done 2026-10-28 (owner)** |
| 1 | Architecture approval + scaffold | 14–22 | 0 | — | app boots in Docker on MariaDB, CI green, arch tests enforce modules |
| 2 | Identity, access, localisation | 10–15 | 1 | SSL Wireless Sender ID + static IP | secure auth en/bn, RBAC, deny-by-default test |
| 3 | Catalog, PIM, inventory core | 15–22 | 2 | — | bilingual products/variants/media/prices/stock; stock concurrency tests |
| 4 | Storefront + search | 12–18 | 3 | — | budgets met on throttled mobile; Bangla search fixtures pass |
| 5 | Cart, checkout, orders, **tax engine** | 18–26 | 3–4 | — | COD order end-to-end, correct totals incl. tax modes |
| 6 | Payments, COD, refunds, reconciliation | 20–30 | 5 | SSLCommerz sandbox (+bKash via SSLCommerz) | replay suite: zero double/wrong/lost payments |
| 7 | Fulfilment, couriers, returns, invoices | 16–24 | 6 | courier API docs/sandbox (any of 4) | pick-pack-ship, tracking sync, returns, COD recon |
| 8 | Digital + services | 15–22 | 6 | — | secure delivery; no double booking |
| 9 | Customer account + engagement | 12–18 | 6–8 | — | self-service orders/returns/reviews/support |
| 10 | Admin, reports, CMS | 18–25 | 5–9 | — | finance daily close ties to statements |
| 11 | Mobile API v1 | 12–18 | 5–9 | — | full journey from OpenAPI alone |
| 12 | Hardening | 12–18 | 1–11 | independent pen-test | go/no-go report |
| 13 | Launch | 6–10 | 12 | live accounts approved | rehearsed deploy/rollback, smoke test |
| 14 | Post-launch cycles | ongoing | live | — | evidence-driven |
**Total to launch ≈ 187–283 person-days (≈ 9–14 calendar months for one developer).**

## 2. Critical path and parallelism
Critical: 1 → 2 → 3 → 5 → 6 → 7 → 12 → 13. Parallelisable with a second person: 4 (after 3), 8 (after 6), 11 (after 5–9 stable), CMS part of 10. Long-lead external items to start now: SSLCommerz merchant onboarding, SSL Wireless transactional Sender ID + fixed server IP, courier API access, VAT registration (tax stays off until then), lawyer/adviser review slots.

## 3. Reconciliation with `docs/AI_Prompts/phases/*`
| Difference | Reason |
|---|---|
| PostgreSQL wording in prompts is void | ADR-000: MariaDB. Use translation table (EXCLUDE→`WITHOUT OVERLAPS`, tsvector/pg_trgm→FULLTEXT+normalisation, partial index→composite, timestamptz→DATETIME(6) UTC, sequences, READ COMMITTED) |
| Phase 5 +3–4 days | configurable tax engine, `order_tax_lines`, inclusive/exclusive (ADR-030) |
| Phase 7 +1–2 days | `invoices` table/PDF + credit notes (tax can be off), multi-courier accounts |
| Phase 3 task 6 | stock commit point = shipment (ADR-013), reservation at order |
| Phase 4 search | FULLTEXT-first; external engine only on trigger (ADR-020) |
| Phase 8 | booking constraint per spike S1; group-capacity sessions need extra design |
| Phase 6 | bKash via SSLCommerz; direct adapter optional later; Nagad not in MVP |
| Phase 2 | SMS adapter = SSL Wireless ISMS Plus; static egress IP |
| Default language | English (owner) — `/` → `en` |
| Spikes S1/S2 | completed in Batch 3 (not scheduled work) |

## 4. Per-phase scope sources
Objectives, tasks, DB, API, UI, security, tests and acceptance criteria are specified in each phase prompt; risks in `RISK_REGISTER.md`; backlog items in `docs/backlog/BACKLOG.md`; human review checkpoints: after the plan (money/stock/auth/schema), after each slice demo, at phase exit gate.

## 5. MVP acceptance checklist
- [ ] Guest + account COD order with correct totals (tax off and on), stock reserved, auditable
- [ ] SSLCommerz sandbox payment incl. bKash method; forged redirect leaves order unpaid; replay suite green
- [ ] Refund cannot exceed captured; manual refund path works
- [ ] Shipment via one courier or ManualCourier; tracking page; COD collected → remitted → reconciled
- [ ] Digital download only with valid entitlement; no public file URL
- [ ] Service booking cannot double-book (parallel test)
- [ ] en/bn complete on all customer pages; Bangla digits accepted
- [ ] Admin RBAC + MFA + audit; PII masked by permission
- [ ] Reports reconcile to ledger; daily close possible
- [ ] Budgets (LCP, size), backups restored, security findings closed
