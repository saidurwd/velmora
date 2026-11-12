# Velmora mobile app API — integration guide (v1)

For the developers of the Android and iOS apps. The **contract** is [`openapi.json`](openapi.json) (every call, field and error, made from the code and checked in CI). This guide is the part a contract cannot say: **how the calls fit together and the rules the server holds you to**. If this guide and `openapi.json` ever disagree, the document wins; tell us.

A ready-made collection to try every call: [`velmora.postman_collection.json`](velmora.postman_collection.json) with [`velmora.postman_environment.json`](velmora.postman_environment.json) (Postman opens them; Bruno imports Postman collections). They are made from the document and cannot drift.

* Base URL: `https://<host>/api/v1`. JSON only. UTC timestamps in ISO 8601.
* Every build of the app sends `X-App-Platform: android|ios` and `X-App-Version: 1.2.3` and `Accept-Language: bn|en` on **every** request.
* Never show or log: access tokens, refresh tokens, cart/order tokens, push tokens.

## 1. Start up

1. `GET /config` — languages, currency, tax mode, the shop's name/phone/email, the CMS pages, and `app.min_supported_version`, `app.latest_version`, `app.store_urls` for your platform. Cache it (it sends `Cache-Control` and an `ETag`).
2. If any call answers **426 `app.upgrade_required`**, show a blocking "please update" screen with the store link from `/config`. Nothing else will work on that build.
3. Language: send `Accept-Language`. Names, messages and error titles come back in that language (Bangla falls back to English where a translation is missing). A signed-in customer's own language setting is used **only** when you send no `Accept-Language`.
4. Money is always `{ "amount_minor": 130000, "currency": "BDT", "formatted": "৳ 1,300.00" }`. **Show `formatted`; compute with `amount_minor` (integer, 1/100 of a taka); never use floats.** The digits in `formatted` follow the shop's setting (Bangla or English digits).

## 2. Signing in

| Call | What it does |
|---|---|
| `POST /auth/register` | Creates an account and emails a verification link (202). **Never returns a token**: the person opens the emailed link (it opens the website today; there are no app deep links yet) and then signs in. The answer is the same whether or not the address already has an account. |
| `POST /auth/login` | Password sign-in. Returns a **token pair**. A wrong password, an unknown or locked account all answer the same 401 `auth.invalid_credentials`; too many tries answer 429 with `Retry-After`. |
| `POST /auth/otp/request` + `/auth/otp/verify` | Sign in with a code sent to the phone. Returns a token pair. |
| `POST /auth/refresh` | Swaps the refresh token for a **new pair**. |
| `POST /auth/logout` / `/auth/logout-all` | Ends this sign-in / every sign-in. |
| `GET /me/tokens`, `DELETE /me/tokens/{id}` | The customer's sign-ins ("devices"), and ending one. |

A token pair is `access_token` (a bearer token, valid `expires_in` seconds, today 60 minutes) and `refresh_token` (valid `refresh_expires_in` seconds, today 30 days), plus `session_id` and the `user`. Send `Authorization: Bearer <access_token>` on every call that needs a customer.

### The refresh rule that matters most: a refresh token works exactly ONCE

* Every `POST /auth/refresh` returns a **new** refresh token. **Store the new pair before using it, and never send the old refresh token again.**
* Send the refresh token a second time (a retry that lost the answer, two screens refreshing at once, a restored backup) and the server treats it as **theft**: the **whole sign-in is ended** and you get 401 `auth.refresh_reused`. The customer has to sign in again.
* So: **refresh from one place only** (one serial queue in the app); every other request that finds the token expired **waits** for that refresh instead of starting its own. Persist the new pair atomically (secure storage: Keychain / Keystore) before releasing the waiting requests.
* Refresh when a call answers 401 `auth.unauthenticated` (or a little before `expires_in` ends). If the refresh answers 401 (`auth.refresh_invalid` or `auth.refresh_reused`), clear the stored tokens and show the sign-in screen. Do not loop.
* A network failure *during* a refresh is the dangerous case: you don't know if the server saw it. Retry **once** only if you are sure the request never left the phone; otherwise treat the sign-in as lost and ask the customer to sign in again. It is safer to lose a sign-in than to reuse a token.

### Re-authentication for sensitive actions

Changing the email or phone, and the privacy actions (data copy, erasure) need a **recent** confirmation: send the password to `POST /auth/reauth` (or ask for a code with `POST /auth/reauth/code` and send that code instead: exactly one of `password` or `code`), then repeat the action within 10 minutes. Without it the call answers 403 `auth.reauth_required`.

## 3. Guests and the cart

A person can browse and fill a cart without an account.

* Public reads (`/products`, `/categories`, `/search`, `/geo/*`, `/pages/*`, `/config`) need nothing.
* The **first** cart write (`POST /cart/items`) returns a `data.token` and the same value in the `X-Cart-Token` response header. Keep it. Send `X-Cart-Token` on **every** cart, checkout and booking-hold call while the person is a guest. Don't send it while signed in.
* After sign-in, call `POST /cart/merge` once with the bearer token **and** the guest `X-Cart-Token`: the guest cart joins the account's cart, and the guest token is finished (delete it).
* Totals, prices, stock and coupons are the server's: always show what `GET /cart` returns. A line can carry a `problem` (out of stock, price changed, no longer sold); show it and let the customer fix it.
* Quantity rules are per product: `variant.min_qty`, `max_qty`, `qty_step` (from `GET /products/{slug}`). Start from `min_qty`.
* A signed-in cart and the website cart are the same cart.

## 4. Checkout and payment

The order of calls:

1. **Address.** `GET /geo/divisions` → `/geo/districts?division=` → `/geo/upazilas?district=`. Signed-in customers can use saved addresses (`/me/addresses`) and send `address.id`.
2. **Quote.** `POST /checkout/quote` with the address, `shipping_method`, `payment_method`. It saves nothing and holds no stock; it answers what would be charged, the delivery options, the ways to pay, whether a phone code is needed (large cash orders) and the policies to accept. Re-quote whenever the address, delivery, payment or cart changes. A cart with only digital goods or services has nothing to deliver: send `contact.name` and `contact.phone` instead of an address.
3. **Place.** `POST /checkout/orders` — the same body plus `expected_total` (the `grand_total.amount_minor` the customer was shown) and the policies they accepted. **It needs an `Idempotency-Key` header** (8–100 characters). Make a **new key for each order the customer confirms** and **reuse the same key for every retry of that same order**: the same key can never make a second order. If the real total differs from `expected_total`, no order is made and you get **409 `checkout.totals_changed`** with a fresh quote: show it and ask again. A large cash order needs a phone proof: `POST /checkout/phone-code` then `/checkout/phone-confirm`, and send the returned `phone_proof`.
4. **The answer** has `order`, `next` (`pay` when an online payment is now due, `none` otherwise) and, for a guest, `order_token`. **Store the order token with the order** (valid 30 days) and send it as `X-Order-Token` on every call about that order. A signed-in customer needs no token.

### Paying online

1. `POST /orders/{id}/payments` (with an `Idempotency-Key`) → `data.redirect_url`. Open it in the system browser or an in-app browser tab; the customer pays on the gateway's page. **Starting again while an attempt is open reuses that attempt** (a double tap cannot make two payments).
2. **The redirect back is not proof.** Nothing the browser, a deep link or the gateway's redirect says changes the order. When the person returns (or the app is reopened), call `POST /orders/{id}/payment/verify`: the **server** asks the gateway and records the answer. Then read `data.paid`.
3. If `paid` is `false` and the person returned from the gateway, the gateway may still be telling us. **Poll** `POST /orders/{id}/payment/verify` (or the cheaper `GET /orders/{id}/payment`) every 3 seconds for about 2 minutes, then stop and show "We're confirming your payment. We'll tell you when it's done" and send the customer to the order screen. Don't poll forever, and never show "paid" until `paid` is `true`.
4. `state` is one of `none | initiated | pending | paid | failed | cancelled | expired | needs_review`; `can_pay` says whether a "pay again" button makes sense. `failed` and `expired` can be retried with a new `POST /orders/{id}/payments`. `needs_review` means a person at the shop is checking it: show the order, not an error.
5. An unpaid online order is cancelled by the shop after its payment time runs out (`expires_at`).

### Following an order

`GET /orders` (cursor pages), `GET /orders/{id}`, `GET /orders/{id}/tracking` (parcels and events), `GET /orders/{id}/invoice`, `POST /orders/{id}/cancel` (if allowed), `POST /orders/{id}/reorder`, and returns under `/orders/{id}/returns`. Statuses are words, not numbers; show the localised label the answer gives and don't build logic on the exact set of values (new ones may appear).

## 5. Digital goods and services

* **Library:** `GET /library`; `POST /library/{id}/download` returns `{ url, filename, remaining, expires_at }` — a short-lived file address (about a minute): **fetch it immediately**, don't store or share it. Each call counts one download against the purchase's limit. `POST /library/{id}/keys` shows licence keys (never cached). Only signed-in customers.
* **Booking a time:** `GET /services/{sku}/slots?date=` → pick a time → `POST /services/{sku}/hold` (puts the booking in the cart; guests get a cart token as in §3). **A hold lasts a few minutes**: go straight to checkout. There is no "confirm booking" call — the booking is confirmed when the order is paid. A time someone else holds is 409 `booking.slot_taken`: show the free times again.
* **My bookings:** `GET /bookings`, and `cancel`, `reschedule`, `complete` where `can_cancel` / `can_reschedule` / `can_complete` say so; `if_cancelled` says what cancelling costs.

## 6. Push notifications

* After every sign-in, and whenever FCM gives the app a new token, call `POST /devices` with `push_token`, `platform` (`android`|`ios`), `app_version`. It is safe to call again (it updates the same device). Delete it with `DELETE /devices/{id}` when the customer turns notifications off in the app. **Signing out removes the device for you.**
* Push is sent for the customer's own orders and bookings only: order placed / paid / shipped / delivered / delivery failed / cancelled / refunded, and booking reminders. Guests are not pushed to (they have no account).
* Marketing push needs a yes: `PUT /me/preferences` with `push: true` (leave `push` out to keep the answer). Order messages are not optional.
* **The payload** (FCM): `notification.title` = the shop's name, `notification.body` = one short line in the language of the request that registered the device (its `Accept-Language`; call `POST /devices` again when the customer changes the app's language), and `data`:
  * `target` — `order:<order id>` or `booking:<booking id>` — the ids you already know from the API. **Open that screen and read the details over the API.** A push never carries amounts, addresses, phone numbers, emails, codes, keys or links; do not expect them, and don't show a push as proof of anything.
  * `template` — which message it is (for example `order.shipped.push`), for analytics.
* The same push can arrive once per phone; use `target` + `template` to avoid showing duplicates in your own inbox if you keep one.

## 7. Errors

Every error is `application/problem+json`:

```json
{ "type": "about:blank", "title": "Not enough stock.", "status": 409, "code": "stock.insufficient",
  "detail": "Only 2 left.", "request_id": "01hz…", "errors": { "field": ["message"] } }
```

* **Decide with `code`** (stable forever, never renamed). `title` and `detail` are localised words for the person; show them, don't parse them.
* `errors` (422 `validation.failed`) maps field names (as in your request, dotted for nesting) to messages in the person's language. Show each next to its field.
* Always keep `request_id` with a bug report.
* **401 `auth.unauthenticated`**: refresh once (§2), then repeat the call once. **403 `auth.reauth_required`**: do the re-authentication (§2). **403 `auth.forbidden`**: show a message.
* **404** is also what you get for someone else's order, booking, address or purchase: it does not tell you whether it exists.
* **409** means the world moved under you (stock, price, totals, a taken time, a conflicting key): fetch the fresh state, show it, let the person decide.
* **429 `rate.limited`**: wait `Retry-After` seconds.
* **500/502/503/504**: temporary; retry as in §9.

### All error codes

| code | HTTP | English title (Bangla comes with `Accept-Language: bn`) |
|---|---|---|
| `validation.failed` | 422 | The given data was invalid. |
| `auth.unauthenticated` | 401 | Authentication is required. |
| `auth.forbidden` | 403 | You are not allowed to do this. |
| `resource.not_found` | 404 | The resource was not found. |
| `http.method_not_allowed` | 405 | This method is not allowed. |
| `rate.limited` | 429 | Too many requests. |
| `server.error` | 500 | An unexpected error occurred. |
| `idempotency.key_required` | 400 |  |
| `idempotency.key_invalid` | 400 |  |
| `idempotency.conflict` | 409 |  |
| `idempotency.in_progress` | 409 |  |
| `app.upgrade_required` | 426 | This version of the app is no longer supported. Please update the app. |
| `auth.invalid_credentials` | 401 | The details are not right. Please check them and try again. |
| `auth.otp_invalid` | 401 | The code is not right or has expired. Please ask for a new one. |
| `auth.refresh_invalid` | 401 | Your sign-in has ended. Please sign in again. |
| `auth.refresh_reused` | 401 | For your safety this sign-in was ended. Please sign in again. |
| `auth.reauth_required` | 403 | Please confirm it is you before doing this. |
| `auth.otp_unavailable` | 503 | We could not send the code just now. Please try again in a moment. |
| `product.not_found` | 404 | This product was not found or is not on sale. |
| `page.not_found` | 404 | This page was not found. |
| `stock.insufficient` | 409 | There is not enough stock for this. |
| `cart.quantity_limit` | 422 | That quantity is not allowed for this item. |
| `cart.too_many_lines` | 422 | The cart cannot hold more different items. |
| `cart.item_unavailable` | 422 | This item cannot be bought right now. |
| `cart.needs_booking` | 422 | A service must be booked at a chosen time first. |
| `cart.item_not_found` | 404 | That item is not in the cart. |
| `coupon.invalid` | 422 | The discount code cannot be used. |
| `cart.empty` | 422 | The cart is empty. |
| `cart.problem` | 409 | Something in the cart needs your attention first. |
| `address.required` | 422 | A delivery address is needed. |
| `address.invalid` | 422 | The address is not valid. |
| `address.limit_reached` | 422 | The address book is full. |
| `delivery.unavailable` | 422 | We cannot deliver to this place yet. |
| `shipping.method_invalid` | 422 | Please choose a delivery option that is offered. |
| `payment.method_invalid` | 422 | This way of paying is not available for this order. |
| `checkout.totals_changed` | 409 | A price or discount changed. Please review the new total. |
| `cod.not_eligible` | 422 | Cash on delivery is not possible for this order right now. |
| `checkout.phone_confirmation_required` | 403 | This order needs the phone number confirmed first. |
| `checkout.phone_invalid` | 422 | Enter a valid Bangladesh mobile number. |
| `checkout.not_accepted` | 422 | We cannot accept this order online. |
| `checkout.busy` | 409 | Another order for this phone number is being finished. Try again in a moment. |
| `booking.hold_expired` | 409 | The time you chose is no longer held for you. |
| `policies.required` | 422 | Please accept the policies to continue. |
| `policies.changed` | 409 | A policy changed. Please read the new version and accept it. |
| `order.not_cancellable` | 409 | This order cannot be cancelled now. |
| `order.already_paid` | 409 | This order does not need paying: it is paid, cancelled or not an online order. |
| `payment.unavailable` | 503 | Payment cannot be started right now. Please try again in a moment. |
| `payment.method_unavailable` | 422 | This way of paying is not available. |
| `payment.expired` | 409 | The time for this payment has run out. |
| `return.not_eligible` | 422 | These items cannot be returned. |
| `review.not_eligible` | 422 | You can review only what you bought, once it has arrived, and soon enough after buying. |
| `review.duplicate` | 409 | You have already reviewed this item. |
| `request.refused` | 422 | This cannot be done right now. |
| `privacy.request_refused` | 409 | This privacy request cannot be made right now. |
| `booking.slot_taken` | 409 | That time is no longer available. Please choose another. |
| `entitlement.limit_reached` | 409 | All the downloads for this purchase have been used. |
| `entitlement.expired` | 409 | The time to download this purchase has run out. |
| `entitlement.revoked` | 409 | This purchase can no longer be downloaded. |
| `entitlement.unavailable` | 409 | This is not ready to download yet. Please try again later. |

Some codes have no fixed title; their `detail` carries the words.

## 8. Lists, caching and images

* **Page lists** (`/products`, `/search`, `/reviews`): `page` and `per_page` (1–60); the answer has `meta` (page, total, last_page, facets) and `links`. **Cursor lists** (`/orders`): `limit` (1–50) and `meta.next_cursor` (send it back as `cursor`; `null` on the last page). Never build page numbers yourself for cursor lists.
* **Sorting and filters:** `sort` and `filter[...]` are listed per call; a wrong value is a 422 that names it, not a silently different list.
* **Caching:** catalog, `/config`, `/geo/*` and `/pages/*` reads send `ETag` and `Cache-Control: max-age`. Send `If-None-Match` and treat **304** as "unchanged". The ETag differs per language. Everything about a customer (cart, orders, library, bookings) is sent `private, no-cache` (free times, licence keys and download addresses `no-store`); don't keep it on disk longer than the screen needs.
* **Images:** each image has `src` (the default) and `srcset` — widths **320, 640, 960, 1280, 1600** pixels (use the nearest width above the size you draw, times the screen density). `width` and `height` describe the image; use them to reserve space so the list doesn't jump. Product galleries carry `alt` text.

## 9. Retries, offline and rate limits

* **Safe to repeat any time** (no side effects): every `GET`.
* **Repeat only with the same `Idempotency-Key`**: `POST /checkout/orders`, `POST /orders/{id}/payments`. The server answers a repeat with the first result and `Idempotency-Replayed: true`. Keep the key with the pending order on disk until you have an answer, so an app restart can retry the same order instead of making a second one. A key reused with a *different* body is 409 `idempotency.conflict`; a repeat while the first call is still running is 409 `idempotency.in_progress` — wait a moment and repeat.
* **Everything else that changes data** (add to cart, address changes, cancel, reviews…) is not keyed: after a lost answer, read the state again (`GET /cart`, `GET /orders/{id}`) before repeating.
* **Back-off:** on 429/500/502/503/504 wait and try again with exponential delay and jitter (1 s, 2 s, 4 s … up to about 30 s, a few tries). If `Retry-After` is present, wait at least that long. Never retry 4xx (except 401 once after a refresh, 409 as above, 429 as above).
* **Offline:** reads can be shown from your cache with an "as of" note. Never queue payments, checkout confirmations, refreshes or cancellations to run later: they must be done with the person looking at a fresh price.
* **Limits** (per customer when signed in; per address for the doors where nobody is signed in yet): public reads 120/min, search 60/min, sign-in and codes 10/min and a few per hour per address, token refresh 60/min per address, signed-in reads 120/min, changes 60/min, placing orders, holding times, starting payments 10/min, downloads 20/min, new support tickets 5/day. These numbers may change; rely on the 429 and `Retry-After`, not on the numbers.

## 10. Changes to the API

See [`VERSIONING_AND_DEPRECATION.md`](VERSIONING_AND_DEPRECATION.md). In short: anything below is **added without warning and your app must tolerate it** — new fields in answers, new values of an answer's status-like fields, new endpoints, new optional request fields, new error codes. Removing or narrowing anything is announced at least 6 months ahead, with `Deprecation` and `Sunset` headers on the affected calls. So: **ignore fields you don't know, treat unknown status values as "other", and show an unknown error code's `title`/`detail`**.
