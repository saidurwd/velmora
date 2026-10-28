# Open Questions, Assumptions and Decisions Log

## Questions for the owner (Q1–Q12 original; Q13–Q14 added for the DB change)
| # | Question | Proposed default | Cost of being wrong |
|---|---|---|---|
| Q1 | Single vendor or marketplace later? | Single vendor; no `vendor_id` unless ADR shows cheap retrofit | Marketplace retrofit touches products/orders/payments: weeks |
| Q2 ✅ English default (owner) | Default storefront language: bn or en? | bn, with en one click away | Low (config) but affects SEO/URL root |
| Q3 ✅ SSLCommerz + bKash (owner) | First online gateway? | One aggregator (SSLCommerz / ShurjoPay / aamarPay) chosen after Q-research | Rework of one adapter, contract isolates it |
| Q4 ✅ multi-courier: Steadfast / Pathao / RedX / Paperfly possible; first adapter picked when official docs available (default: Steadfast) + ManualCourier | First courier? | Steadfast or Pathao + ManualCourier | One adapter |
| Q5 ✅ SSL Wireless (company behind SSLCommerz) ISMS Plus as first `SmsGateway` adapter — owner wrote "SSLCommerce"; confirm it means SSL Wireless SMS | SMS provider / BTRC sender-ID status? | Shortlist 2; start sender-ID now | OTP launch blocked for weeks |
| Q6 ✅ not registered yet, will register; everything configurable (partly: owner wants VAT/tax feature built; registration/BIN/rates still to confirm with adviser) | VAT registered (BIN)? Invoice model? | Assume registered; Mushak-style invoice, lawyer to verify | Invoice/report redesign |
| Q7 | Hosting region & provider? | VPS/cloud with managed Postgres, region nearest BD | Latency, data-residency rework |
| Q8 | COD policy: fee, max value, phone-confirmation, auto-cancel hours? | Configurable; confirm calls for first orders & > ৳5,000; auto-cancel 24 h | Fraud losses / conversion |
| Q9 | Purchasing/suppliers in MVP? | No (backlog) | Added 5–8 days |
| Q10 | Return/refund policy (windows, who pays shipping)? | 7-day return for physical, non-refundable after digital download | Policy engine data, legal text |
| Q11 | Team size and target launch date? | 1 senior dev + AI; ~10–13 months | Scope cut needed |
| Q12 | Digital goods prepaid-only? Services pay-at-booking or after? | Digital prepaid-only; services prepaid or deposit | Booking/payment flow change |

## Database
Resolved: owner chose MariaDB/MySQL. `velmora` exists on local MariaDB 12.1.2 (verified). See `DATABASE_ENGINE_DECISION.md`.
| Q13 ✅ MariaDB (owner, 2026-10-28) | MariaDB or MySQL as the final engine (also production)? | MariaDB ≥ 11.4 LTS (matches dev server; has SEQUENCE and possibly WITHOUT OVERLAPS) | Booking constraint and search design differ per engine |
| Q14 ✅ Yes, created (owner, 2026-10-28) | May I create `velmora_test` for automated tests (tests will truncate it)? | Yes, separate from `velmora` | Tests could not run on real DB |

## Assumptions
A1 Single legal entity, BDT only. A2 1 senior dev + part-time QA/design. A3 Aggregator gateway + COD first. A4 MariaDB ≥ 11.4 (dev: 12.1.2), InnoDB, utf8mb4. A5 Targets in REQ-060/061 are proposals.

## Decisions log
| Date | Decision | By |
|---|---|---|
| 2026-10-28 | **Database = MariaDB/MySQL, not PostgreSQL** (ADR-000) | Owner |
| 2026-10-28 | SMS gateway = SSL Wireless ISMS Plus (assumed from "SSLCommerce"); Batch 6 drafted | Owner |
| 2026-10-28 | Default language = English; couriers: any of four, contract multi-provider; Batch 5 approved → Batch 6 (Q5 SMS, Q7 hosting proceed on defaults) | Owner |
| 2026-10-28 | Gateway = SSLCommerz + bKash; tax fully configurable (not VAT-registered yet); lakh/crore grouping in bn approved; Batch 4 approved → Batch 5 | Owner |
| 2026-10-28 | Batch 3 approved as design draft (column-complete via migrations); build configurable VAT/tax feature; proceed to Batch 4 | Owner |
| 2026-10-28 | Stock commit at shipment; no `vendor_id`; spikes S1/S2 run; Batch 2 approved | Owner |
| 2026-10-28 | Engine = MariaDB ≥ 11.4; `velmora_test` created; Phase 0 signed off; Q1–Q12 answered step by step | Owner |
| 2026-10-28 | Proceed with Phase 0 on pack's baseline decisions; use existing `velmora` database where needed | Owner |

## Added by Batch 7 audit
| # | Item | Default |
|---|---|---|
| Q15 | CAPTCHA / anti-abuse vendor for OTP abuse | none at MVP; per-IP/number/global caps first |
| Q16 | Email delivery provider | SMTP via provider chosen with hosting (Q7); Mailpit in dev |
| Q17 | Hosting provider/region (Q7 restated) | default VPS/cloud near BD latency; managed MariaDB if available |
Assumption values: see CONSISTENCY_AUDIT.md "Assumptions to carry".
