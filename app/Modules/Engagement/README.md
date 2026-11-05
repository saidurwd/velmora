

Customer engagement (Phase 9). Slice 3 holds the wishlist and back-in-stock alerts; reviews follow in slice 4.

* Tables: `wishlist_items`, `stock_alerts` (both signed-in customers only; `UNIQUE (user_id, variant_id)`).
* Actions: `AddToWishlist`, `RemoveFromWishlist`, `MoveWishlistItemToCart` (through `Cart`'s `CartFiller`), `SubscribeStockAlert`, `CancelStockAlert`, `NotifyBackInStock` (every five minutes via `Jobs/NotifyBackInStockJob`; oldest first, at most 2× the available units and 100 per variant per run, one message per person).
* Pages: `/account/wishlist`, and the buttons under "Add to cart" on a product page (`resources/views/partials/product-actions`, included by the Catalog product page).
* Messages: `Notifications/EngagementTemplates` (`engagement.back_in_stock.email|sms`, transactional); staff may change the words.
* `Privacy/EngagementPersonalData` is this module's part of the data copy and erasure.
* Settings in `config/engagement.php`: `wishlist_max` (200), `alerts_max` (100).

Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).

