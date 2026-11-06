
* Phase 9, slice 3: `Contracts/CartFiller` (implemented by `Services/CartLineFiller` over `AddToCart`) is how other modules put an item in a cart: today's price, the cart's own rules, the part that fits when stock or the per-line limit is short, and a result (`CartFillResult`) instead of an exception for a line that cannot go in.
* Phase 9, slice 7: `Contracts/AbandonedCarts` (read-only, DTOs) lists signed-in customers' carts that have lines and have not changed for a while, oldest first, a page at a time; guest and empty carts are never listed. The reminder itself lives in Engagement.
