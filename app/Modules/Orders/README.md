# Orders module

Owns: `orders`, `order_items`, `order_addresses`, `order_status_history`, `order_tax_lines`, `order_notes`.
Writes an order from a priced cart (`CreateOrder`), moves its three states (`TransitionOrder`: order status, payment, delivery), admin list and detail pages.
Checkout (`PlaceOrder`) is added in Phase 5 slice 7 and composes cart, pricing, shipping, inventory and `CreateOrder` in one transaction.

Rules worth knowing:
- An order is a snapshot. It never reads the live catalog or prices after it is written.
- Money, identity and time columns are frozen by database triggers; only the state columns change. Nothing is deleted.
- Every state change locks the order row, checks the move against the enum and the cross-rules, and writes a history row in the same transaction.

Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).
* Phase 9, slice 1b: order messages (placed, cash-on-delivery placed, shipped, delivered, cancelled, refunded) are templates in `Notifications/OrderTemplates`, sent through `Services/OrderMessages` → `NotificationSender` in the order's language. `CustomerSms` and `OrderPlacedMail` are gone. Delivery is queued: production needs the queue worker and the scheduler.
* Phase 9, slice 2: `Privacy/OrderPersonalData` exports a customer's orders and, on erasure, replaces the contact and delivery address by fixed placeholders (allowed by the `orders_erasure_scrub_only` trigger only as exact placeholders, once, with `anonymised_at`); amounts, items and tax are never touched. An order that is pending, confirmed or processing blocks erasure.
