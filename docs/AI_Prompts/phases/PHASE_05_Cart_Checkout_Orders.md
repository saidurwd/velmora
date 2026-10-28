# Phase 5 — Cart, Addresses, Shipping Quotes & Checkout (creates the Order)

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | 15–22 person-days |
| **Prerequisites** | Phases 3–4 |
| **Modules** | Cart, Customers (addresses), Shipping (zones/rates only), Pricing (coupons, tax), Checkout, **Orders (core + state machines)**, Inventory (reservations) |
| **Attach** | `DATABASE_DESIGN.md` (orders, cart, shipping), `BANGLADESH_LOCALIZATION_AND_COMMERCE.md`, `PAYMENT_INTEGRATION_DESIGN.md` (contract only) |
| **Exit gate** | A guest or customer can place a COD order end-to-end with correct totals, reserved stock and an auditable order record |

> **Change vs Draft.md:** the Order aggregate and its three state machines are created here (checkout needs an order to create). Phase 6 attaches payments to it.

## Copy-paste prompt

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

OBJECTIVE
Trustworthy totals and a safe, fast checkout for Bangladesh: guest-friendly, mobile-first, COD-ready.

TASKS
1. **Geography & addresses:** seed `divisions/districts/upazilas` (+ bn names; record dataset source/date); `customer_addresses` (recipient, phone, division, district, upazila/thana, area, address line, landmark, delivery note, default flags); address form with cascading selects that work without JS fallback; BD phone validation via `PhoneNumber`.
2. **Cart:** persistent cart for guests (signed cookie token) and users; merge on login; line add/update/remove; quantity limits (min/max/step); live availability check; price/availability change notices ("price changed since you added this"); cart expiry job; **cart totals are always recomputed server-side**.
3. **Pricing engine:** `PricingContext` → `PriceBreakdown` (subtotal, line discounts, order discounts, shipping, COD fee, tax, grand total) using Money allocation so rounding never leaks paisa. Tax: configurable tax classes/rates, inclusive/exclusive, rate snapshot on order lines (🔎 rate and rules from verified source).
4. **Coupons & promotions (MVP):** coupon codes (percent/fixed/free-shipping), min spend, product/category scope, validity window, global + per-customer usage limits, stackability rules, automatic cart-level promotions (simple). Atomic redemption pattern (BD §7). Admin CRUD + usage report.
5. **Shipping quotes:** zones, methods, rate bands, free-shipping thresholds, COD eligibility/fee rules, estimated delivery time text (bn/en). `ShippingQuote` DTO. Digital-only and service-only carts skip shipping.
6. **Checkout:** single-page streamlined flow (contact → address → shipping → payment method → review), guest checkout (phone required, email optional), account creation optional post-order, server-side validation at each step, **idempotent `PlaceOrder` Action**:
   - one DB transaction: re-validate cart & prices → reserve stock (atomic) → redeem coupon (atomic) → create order + lines (snapshots) + addresses snapshot + status history → clear cart → write outbox `OrderPlaced`.
   - payment method selection recorded; for **COD** order moves to `confirmed` or stays `pending` pending phone confirmation (config flag).
   - online payment methods: order created `pending/unpaid`, redirect step stubbed behind `PaymentInitiator` contract (real gateway in Phase 6).
7. **Orders core:** `orders`, `order_items`, `order_addresses`, `order_status_history`, `order_notes`; three state-machine classes (BD §7) with transition guards + history + audit; order number sequence; public order-confirmation page (signed link/phone verification); order-placed email/SMS (via Notifications contract).
8. **Fraud/abuse basics (COD):** configurable rules — max open COD orders per phone, block-list (phone/address), high-value COD requires prepayment/OTP confirmation, velocity limits; flagged orders go to `needs_review`.
9. **Admin:** shipping zones/rates screens, coupon screens, order list/detail (read + notes + cancel for pending), fraud-rule settings.

DATABASE: geography, addresses, carts, cart_items, coupons, coupon_redemptions, promotions, tax_classes/rates, shipping_zones/methods/rates, orders*, order_status_history. Indexes for order lookup by number, phone, status, created_at. CHECKs: totals ≥ 0, quantity > 0, `grand_total = subtotal - discounts + shipping + cod_fee + tax` (enforced by test and, where practical, a CHECK).
API readiness: every checkout step is an Action with DTO in/out.

TESTS (must pass)
- Two customers place orders for the last unit simultaneously → one order, one clean "out of stock" error, no oversell.
- Coupon with limit 1 redeemed concurrently by 10 sessions → exactly 1 succeeds; per-customer limit enforced.
- `PlaceOrder` retried with same idempotency key → one order. Browser double-submit → one order.
- Price changes between cart and submit → customer sees diff, order not placed silently at new price.
- Totals property tests: for random carts/discounts/tax modes, parts always sum to total; allocation exact to the paisa.
- Shipping: Dhaka vs outside Dhaka, free-shipping threshold, COD fee, digital-only skip.
- Guest cannot see another guest's order; signed link tamper → 403/404.
- Merge-on-login, cart expiry, Bangla digits in quantity/phone fields.
- Transaction rollback test: failure after stock reservation leaves no reservation, no coupon use, no order.

ACCEPTANCE CRITERIA
- A COD order from a throttled mobile device completes in ≤ 4 taps after cart (excluding typing) and ≤ 800 ms server time for the submit.
- Order totals are reproducible from snapshot lines alone (no live catalog reads).
- Every state change is in history with actor and reason.

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
