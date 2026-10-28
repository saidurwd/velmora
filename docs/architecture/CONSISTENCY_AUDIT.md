# Consistency Audit — Batches 1–7 (2026-10-28)

Run with scripts over the documents plus manual review. "Evidence" is what was actually checked.

| Check | Result | Evidence |
|---|---|---|
| Every table in the ER diagram exists in the data dictionary | **Pass** | 35 ER entities, 0 missing (script) |
| Every dictionary table appears in the ER diagram | **N/A** | ER shows key entities only; per-module ER diagrams to be generated from migrations (Phase 1) |
| Every module event has a producer and ≥ 1 consumer or is "audit only" | **Pass after fix** | Audit found 6 events used in the module doc but absent from the catalogue (UserRegistered, CustomerAnonymised, CouponRedeemed, StockReserved, ReturnReceived, ReviewPublished); added with consumers. Checked by grep on 10 sampled events; full machine check deferred to Phase 1 (event registry test) |
| No module depends on another module's Models/tables | **Pass (design)** | Dependency matrix has only Contracts/DTO/Events; enforced later by arch tests |
| Every money column is bigint minor units + currency | **Pass (design)** | 0 DECIMAL/FLOAT/DOUBLE in dictionary (script); column-level proof deferred to migrations + "no float money" test |
| Every status machine has states, transitions, triggers, side-effects, history | **Partial** | Order (3 axes), payment, transaction, refund, booking, shipment, return documented; **trigger/side-effect tables for each are summarised, not exhaustive** → complete in Phase 5/6/7 ADRs |
| 10 + 7 high-risk scenarios map to a mechanism and a planned test | **Pass** | 17 rows in TESTING_AND_QA §3 with mechanism and test |
| Each API endpoint maps to an Action, permission and rate-limit tier | **Pass after fix** | 53 endpoint rows; 4 lacked named Actions → named. Permission = Policy/ownership scoping (staff permissions N/A in v1) |
| Each phase has objectively testable acceptance criteria | **Pass** | `phases/PHASE_00..14` each contain acceptance criteria and tests |
| Every `[ASSUMPTION]` / `[NEEDS-HUMAN-DECISION]` appears in OPEN_QUESTIONS | **Partial** | `NEEDS-HUMAN-DECISION`: CAPTCHA vendor (SECURITY) not in OPEN_QUESTIONS → added below. Several `[ASSUMPTION]` values (retention periods, sizing, backup retention) are in their docs only → listed below |
| No PostgreSQL-only design left in architecture docs | **Pass after fix** | grep: only REQ-065 still said PostgreSQL → fixed; remaining mentions are ADR-000 explanations. `docs/AI_Prompts/*` carry override banners |
| Docs consistent with owner decisions | **Pass** | English default; MariaDB; SSLCommerz+bKash(via SSLCommerz); SSL Wireless SMS; configurable tax; stock commit at shipment; no vendor_id; lakh/crore bn grouping |

## Assumptions to carry into OPEN_QUESTIONS
CAPTCHA/anti-abuse vendor [NEEDS-HUMAN-DECISION] · log IP retention 12 m · catalog purge 2 y · raw webhook retention 18 m · backup retention 35 d/12 m · sizing 500 orders/day, 10× peak · access token 60 min / refresh 30 d · rate-limit numbers · records retention ≥ 6 y · shipping-tax and COD-fee taxability defaults.

## Known gaps (honest list)
1. Data dictionary is design-level, not column-complete.
2. Provider docs not fully available: ShurjoPay, aamarPay (partial), bKash, Nagad, all couriers, NBR/VAT official text, BTRC.
3. Spike coverage: S1/S2 on MariaDB 12.1.2 only; group-capacity bookings and large-volume Bangla search unproven.
4. Laravel/PHP versions, Sanctum refresh-token approach, spatie/laravel-permission suitability not verified (ADR-001/021 pending).
5. Hosting provider (Q7) and email provider not chosen.
6. Legal/tax items unverified; lawyer and tax adviser reviews required.
