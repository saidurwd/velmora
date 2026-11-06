

Slice 4 adds reviews:

* Tables: `reviews` (one per order line; status moves enforced by trigger), `product_rating_stats` (star counts, changed with the decision that publishes or removes a review).
* Proof of purchase comes from Orders' `Contracts/PurchasedLines`. Actions: `SubmitReview`, `WithdrawReview`, `ModerateReview` (approve, reject, take down); `Services/RatingStats`, `Services/ReviewReader`.
* Pages: the form `/account/orders/{order}/reviews/{variant}`, `/account/reviews`, the block on the product page (`partials/product-reviews`) and on the order page (`partials/order-reviews`); staff `/admin/reviews` (`reviews.moderate`).
* Setting in `config/engagement.php`: `review_window_days` (180).

