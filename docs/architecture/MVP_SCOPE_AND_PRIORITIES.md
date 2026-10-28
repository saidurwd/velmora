# MVP Scope and Priorities (Phase 0 draft)

Starting point: `02_BASELINE_DECISIONS.md` §15. **[ASSUMPTION]** single vendor, BDT only, 1 senior dev + AI agents.

| Capability | Class | Notes |
|---|---|---|
| Physical + digital + service catalog, variants, bn/en content | MVP | Phase 3 |
| Cart, guest + account checkout, COD, one online gateway | MVP | Phases 5–6 |
| One courier + manual fallback | MVP | Phase 7 |
| Basic coupons, order tracking, email + SMS | MVP | |
| Admin: catalog, orders, payments, inventory, shipments, CMS, RBAC, audit | MVP | Phase 10 |
| Digital delivery and service bookings | MVP | Phase 8 — **flag:** could slip to MVP+1 if schedule tight |
| Reviews, wishlist, returns portal, support tickets | MVP-late | Phase 9 |
| Mobile API v1 | Post-web | Phase 11; designed from day 1 |
| Direct bKash/Nagad, extra couriers, advanced search, loyalty, push | Phase 2 | |
| Purchasing/suppliers | Backlog | Only if owner confirms (Q9) |
| Subscriptions, gift cards, B2B, pre-orders | Future | |
| Marketplace/vendors | Future | Reserve nullable `vendor_id`? — ADR decision (Q1) |

## Schedule check
Roadmap total ≈ 180–270 person-days (README). With one developer that is ≈ 9–13 months of calendar time. **Flag:** if launch is required sooner, cut Phase 8 (digital/services) and Phase 9 extras from MVP, or add people for Phases 4/8/11.

## DB change impact
Add spikes **S1 (booking overlap)** and **S2 (Bangla search engine)** before Phase 3; may add 3–5 person-days. External search engine may move into MVP (ADR).
