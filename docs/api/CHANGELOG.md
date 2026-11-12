# API changelog (v1)

Newest first. "Added" never breaks an app. "Deprecated" starts the notice period in `VERSIONING_AND_DEPRECATION.md`. "Removed" only happens after a sunset date. Nothing is deprecated or removed yet.

## v1.0.0 — first release (Phase 11)

Added, all at once because nothing was out there before:

* Sign-in: register, password and phone-code sign-in, single-use refresh tokens, sessions list, re-authentication, password reset. Push devices.
* Catalog (read only): products, categories, brands, collections, search and autocomplete, reviews, geography, CMS pages, app config.
* Cart and checkout for guests and customers: cart, coupons, merge, quote, phone code, place order with idempotency.
* Payments, orders and tracking: start and verify a payment, orders, invoice, cancel, reorder, returns, parcel tracking.
* Account: profile, addresses, contact changes, preferences (including optional `push`), privacy (data copy, erasure), wishlist and stock alerts, reviews, support tickets.
* Digital goods and bookings: library, downloads, licence keys, free times, holds, my bookings.
* Push notifications for orders and booking reminders (FCM payload described in the guide).
