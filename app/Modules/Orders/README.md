# Orders module

Owns: `orders`, `order_items`, `order_addresses`, `order_status_history`, `order_tax_lines`, `order_notes`.
Writes an order from a priced cart (`CreateOrder`), moves its three states (`TransitionOrder`: order status, payment, delivery), admin list and detail pages.
Checkout (`PlaceOrder`) is added in Phase 5 slice 7 and composes cart, pricing, shipping, inventory and `CreateOrder` in one transaction.

Rules worth knowing:
- An order is a snapshot. It never reads the live catalog or prices after it is written.
- Money, identity and time columns are frozen by database triggers; only the state columns change. Nothing is deleted.
- Every state change locks the order row, checks the move against the enum and the cross-rules, and writes a history row in the same transaction.

Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).
