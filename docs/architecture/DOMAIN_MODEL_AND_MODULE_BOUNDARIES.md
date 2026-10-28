# Domain Model and Module Boundaries (Batch 2 draft)

Rule [PINNED]: a module may use another module's **Contracts, DTOs, Enums, Events** only — never Models, Actions or tables. Cross-module writes go through events (after-commit/outbox) or a contract. Enforced by Pest arch tests / Deptrac.

## Modules
| Module | Responsibilities | Owned tables (examples; final in Batch 3) | Emits | Consumes | Must NOT depend on |
|---|---|---|---|---|---|
| Platform (kernel) | Money, Clock, RequestContext, Settings, Audit, Idempotency, Outbox, PublicId, PhoneNumber, BanglaText, languages | audit_events, idempotency_keys, outbox_messages, settings, languages | AuditEvent | — | any module |
| IdentityAccess | users, OTP, staff MFA, RBAC, tokens | users, otp_challenges, roles, permissions, staff_mfa_credentials | UserRegistered | — | Orders, Catalog |
| Customers | profile, addresses, geography, consent | customers, customer_addresses, divisions, districts, upazilas | CustomerAnonymised | UserRegistered | Orders models |
| Catalog | products, variants, translations, taxonomy, media, attributes | products*, product_variants, categories*, brands*, product_media | ProductPublished/Updated | — | Inventory internals |
| Pricing/Promotions | prices, price resolver, tax, coupons, promotions | prices, tax_classes, tax_rates, coupons, coupon_redemptions | CouponRedeemed | ProductUpdated | Orders |
| Inventory | locations, levels, movements, reservations, availability | stock_locations, stock_levels, stock_movements, stock_reservations | StockLow, StockReserved | OrderCancelled, ShipmentCreated, ReturnReceived | Catalog models (uses contracts) |
| Cart | cart & lines, merge, expiry | carts, cart_items | — | — | Orders |
| Checkout | orchestrates PlaceOrder (stateless) | none (uses idempotency_keys) | — | — | owns no tables |
| Orders | order aggregate, 3 state machines, history, notes | orders, order_items, order_addresses, order_status_history | OrderPlaced/Confirmed/Cancelled/Paid | PaymentSucceeded/Failed, Shipment*, Refund* | **Payments (never calls)** |
| Payments | gateways, payments, transactions, webhooks, refunds, COD collections/remittances | payments, payment_transactions, payment_webhook_events, refunds, cod_* | PaymentSucceeded/Failed/NeedsReview, RefundSucceeded, CodCollected | OrderPlaced (via OrderPayable contract) | Orders models |
| Shipping | zones, rates, quotes, shipments, courier providers, returns ops | shipping_*, shipments, shipment_items, shipment_events, courier_accounts, return_requests | Shipment* | OrderConfirmed | Payments |
| DigitalProducts | assets, entitlements, downloads, license keys | digital_assets, entitlements, download_logs, license_keys | EntitlementGranted | OrderPaid, RefundSucceeded | Shipping, Inventory |
| Services | definitions, packages, resources, availability, bookings | service_*, availability_rules, service_bookings | BookingConfirmed/Cancelled | OrderPaid/Confirmed | Inventory |
| Engagement | reviews, wishlist, support tickets | reviews, wishlists, support_* | ReviewPublished | ShipmentDelivered | Payments |
| Notifications | channels, templates, logs, preferences | notification_templates, notification_logs, notification_preferences | — | most events | domain models |
| Cms | pages, banners, menus, policies | cms_pages*, banners*, policy_versions | — | — | — |
| Integrations | provider settings, credentials (encrypted) | integration_configs | — | — | — |
| Reporting (read-only) | metrics, reports, exports | summary tables/views | — | all events (read) | writes to others |
| Search | `CatalogSearch` contract + engine adapter | search_synonyms, search_queries | — | ProductPublished | — |

## Dependency matrix (row may use column's contracts)
| ↓ uses → | Plat | Id | Cust | Cat | Prc | Inv | Cart | Ship | Ord | Pay | Dig | Svc | Notif |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Catalog | ✔ | | | | | | | | | | | | |
| Pricing | ✔ | | | ✔ | | | | | | | | | |
| Inventory | ✔ | | | ✔ | | | | | | | | | |
| Cart | ✔ | | | ✔ | ✔ | ✔ | | | | | | | |
| Checkout | ✔ | | ✔ | | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | | | |
| Orders | ✔ | | | ✔(DTO) | | | | | | | | | |
| Payments | ✔ | | | | | | | | ✔(OrderPayable) | | | | |
| Shipping | ✔ | | ✔ | | | | | | ✔ | | | | |
| Digital | ✔ | | | ✔ | | | | | ✔ | | | | |
| Services | ✔ | | | ✔ | | | | | ✔ | | | | |
| Notifications | ✔ | | | | | | | | | | | | |
Everything depends on Platform; no cycles. Orders ⟶ Payments is forbidden (events only).

## State machines (rule for every axis)
Each has: states, allowed transitions, trigger (actor/event), side-effects, history row. Detailed tables in Batch 3.
- **Order status:** pending → confirmed → processing → completed; cancelled from pending/confirmed/processing (pre-shipment); `needs_review` flag.
- **Payment status:** unpaid, pending, paid, partially_refunded, refunded, failed. **Transaction:** initiated → pending → succeeded|failed|cancelled|expired (terminal never regresses).
- **Fulfilment:** unfulfilled, partially_fulfilled, fulfilled, partially_returned, returned.
- **Booking:** held → confirmed → in_progress → completed; cancelled, no_show, rescheduled.

## Product-type behaviour
| Type | Inventory | Fulfilment | Refund |
|---|---|---|---|
| physical | stock_levels | Shipment | return window |
| digital | none (key pool optional) | Entitlement on OrderPaid | restricted after download |
| service | slots/capacity | Booking lifecycle | policy windows |
Services never touch `stock_*`; digital never creates shipments.

## Open items for Batch 3
Reserve `vendor_id` (Q1); stock commit point (ADR-013); booking constraint (S1).
