
* Phase 9, slice 3: `Contracts/CartFiller` (implemented by `Services/CartLineFiller` over `AddToCart`) is how other modules put an item in a cart: today's price, the cart's own rules, the part that fits when stock or the per-line limit is short, and a result (`CartFillResult`) instead of an exception for a line that cannot go in.
