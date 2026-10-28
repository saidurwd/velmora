# Business Requirements (Phase 0 draft)

Priority: **M**ust / **S**hould / **C**ould / **W**on't (now). Each REQ has an acceptance test (AT). Source: Draft.md section in brackets.

## Catalog & content [Draft §3, §5]
| ID | Pri | Requirement | Acceptance test |
|---|---|---|---|
| REQ-001 | M | Products of type physical, digital, service share one catalog with type-specific fulfilment | AT: create one of each; each follows its own fulfilment path with no cross-effects |
| REQ-002 | M | Variants (size/colour/model) with unique SKU; simple products have one default variant | AT: duplicate SKU rejected |
| REQ-003 | M | All customer-facing content stored in en and bn; en mandatory, bn falls back to en | AT: missing bn shows en and admin flags it |
| REQ-004 | M | Categories, brands, attributes, tags, collections, media with per-locale alt text | AT: publish product with 3 variants + images < 5 min |
| REQ-005 | M | Base, compare-at and scheduled sale prices in BDT minor units | AT: sale window boundary correct in Asia/Dhaka |
| REQ-006 | S | Bundles/kits | AT: deferred |
| REQ-007 | M | Bangla + English search incl. autocomplete, typos, Bangla digits, synonyms | AT: relevance fixture suite passes |
## Cart, checkout, orders [§5, §6]
| REQ-010 | M | Guest and account checkout; phone required, email optional | AT: guest order placed with phone only |
| REQ-011 | M | Server-side recomputed totals; price-change notice; no silent new price | AT: price change between cart and submit shows diff |
| REQ-012 | M | Stock reserved atomically at order creation; no overselling | AT: N parallel buyers for last unit → 1 order |
| REQ-013 | M | Coupons (percent/fixed/free-shipping) with global and per-customer limits | AT: limit-1 coupon under 10 parallel sessions → 1 redemption |
| REQ-014 | M | Idempotent order placement (double submit/retry → one order) | AT: replay same key → same order |
| REQ-015 | M | Order lifecycle on three axes (status, payment, fulfilment) with history and audit | AT: every transition has actor+reason row |
| REQ-016 | M | Order number `ORD-YYMM-NNNNNN`; public lookup needs number + phone/email or signed token | AT: enumeration fails |
## Payments [§6]
| REQ-020 | M | Cash on Delivery with configurable fee, eligibility (zone/value/fraud) | AT: COD blocked above threshold per config |
| REQ-021 | M | At least one online gateway (aggregator) via provider-independent contract | AT: swap fake gateway without touching checkout |
| REQ-022 | M | Order paid only after server-to-server verification of amount, currency, reference, status | AT: forged success redirect leaves order unpaid |
| REQ-023 | M | Duplicate/late/out-of-order/forged callbacks handled idempotently | AT: replay suite zero double-paid |
| REQ-024 | M | Refunds (full/partial) never exceed captured amount; approval permission | AT: concurrent refunds over cap → one rejected |
| REQ-025 | M | Reconciliation of pending payments and gateway settlements; exceptions screen | AT: injected mismatch appears in queue |
| REQ-026 | S | bKash/Nagad direct | AT: Phase 2 unless aggregator covers |
## Shipping & fulfilment [§6]
| REQ-030 | M | Zones (Inside Dhaka / suburbs / outside / custom), weight and value bands, free-shipping threshold | AT: quote matches rate table |
| REQ-031 | M | Division/district/upazila address model with bn names | AT: cascading select works without JS |
| REQ-032 | M | One courier integration + ManualCourier fallback | AT: courier API down → manual flow continues |
| REQ-033 | M | Shipment tracking sync, partial shipments, failed delivery, return-to-origin | AT: out-of-order events yield correct final state |
| REQ-034 | M | COD collection → remittance → reconciliation, short-remittance flagged | AT: aging report lists uncollected |
| REQ-035 | S | Returns/RMA with restock/quarantine/write-off | AT: stock ledger correct |
## Digital & services [§3 B, C]
| REQ-040 | M | Private storage, entitlement-checked short-lived download, limits, expiry, audit | AT: no permanent public URL |
| REQ-041 | S | License-key pool with atomic allocation | AT: each key issued once |
| REQ-042 | M | Service slots, holds with TTL, DB-enforced no double-booking | AT: 20 parallel bookings → 1 confirmed |
| REQ-043 | M | Cancellation/refund windows from policy rows | AT: fee matrix test |
## Identity, admin, platform [§5, §11]
| REQ-050 | M | Email+password or BD phone+OTP login; enumeration-safe; SMS-pumping guards | AT: brute-force/rate-limit tests |
| REQ-051 | M | Staff: separate guard, mandatory TOTP MFA, RBAC `<resource>.<action>`, last Super Admin protected | AT: route-permission enumeration test |
| REQ-052 | M | Audit log for admin writes and financial/stock changes | AT: price change creates audit row |
| REQ-053 | M | Admin: catalog, orders, payments, inventory, shipments, CMS, coupons, settings | AT: per phase |
| REQ-054 | M | Reports with one definition per metric (gross, discounts, delivery, tax, collected, refunded, net, recognised) | AT: report totals = ledger |
| REQ-055 | S | Reviews, wishlist, support tickets, returns portal | AT: Phase 9 |
| REQ-056 | S | Email + SMS notifications bn/en, delivery log | AT: failure visible to staff |
## Non-functional [§12–§14]
| REQ-060 | M | Mobile LCP ≤ 2.5 s p75; home ≤ 1.0 MB first load; checkout API p95 ≤ 800 ms (proposed targets) | AT: Lighthouse CI / k6 |
| REQ-061 | M | Availability ≥ 99.5 %; RPO ≤ 15 min; RTO ≤ 4 h (proposed) | AT: restore rehearsal |
| REQ-062 | M | WCAG 2.2 AA; correct Bangla rendering | AT: axe + visual snapshots |
| REQ-063 | M | Versioned REST API sharing Actions with web | AT: parity tests |
| REQ-064 | M | No secrets/raw card data stored; PII redacted in logs | AT: redaction test |
| REQ-065 | M | Tests run on real MariaDB (`velmora_test`, never SQLite); ten Draft high-risk + seven extra scenarios automated | AT: Phase 12 gate |
