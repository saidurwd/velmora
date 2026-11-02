# Architecture Decision Records (Batch 2)

Format: Context → Options → Decision → Consequences → Reversal. Status: **Proposed** until the owner approves Batch 2. Versions marked [UNVERIFIED] must be checked against official sources in Phase 1.

| ADR | Decision | Status |
|---|---|---|
| 000 | MariaDB ≥ 11.4 instead of PostgreSQL → `DATABASE_ENGINE_DECISION.md` | **Accepted** |
| 001 | Laravel 13.39.0 (latest stable on 2026-10-27, requires PHP ^8.3) on PHP 8.4.27; Pest 4.7.8, Larastan 3.13.2 (level 8), Pint 1.32.1, Horizon 5.50.1, Node 22, Tailwind 4, Alpine; verified working against MariaDB 12.1.2 | **Accepted (owner, 2026-10-28)** |
| 002 | Modular monolith in `app/Modules/<Module>` with contract-only cross-module use (BD-10/11) | Proposed |
| 003 | Blade + Tailwind + Alpine via Vite; no SPA/Livewire (BD-03) | Proposed |
| 004 | Money = BIGINT minor units + CHAR(3) currency; `Money` value object (BD-20) | Proposed |
| 005 | Ids: BIGINT UNSIGNED internal + `CHAR(26) ascii_bin` ULID `public_id` (BD-21) | Proposed |
| 006 | Time: UTC in `DATETIME(6)`, display Asia/Dhaka, connection tz `+00:00` (BD-22) | Proposed |
| 007 | Soft delete for catalog/CMS only; financial & stock append-only enforced by triggers + privileges (BD-23) | Proposed |
| 008 | Enums as VARCHAR + CHECK (BD-24) | Proposed |
| 009 | JSON only for provider payloads/free-form; queried fields are columns (BD-26) | Proposed |
| 010 | Order lines snapshot price/name/tax (BD-27) | Proposed |
| 011 | Translations: normalised `*_translations` tables (BD-30) | Proposed |
| 012 | Sellable unit = `product_variants`; type drives fulfilment (BD-40) | Proposed |
| 013 | Stock: atomic conditional UPDATE + append-only ledger; reserve at order creation, decrement `on_hand` at shipment | **Accepted (owner, 2026-10-28)** |
| 014 | Transaction isolation READ COMMITTED for order/payment/stock; ordered locks; bounded deadlock retry | Proposed (new) |
| 015 | Three-axis order status with one state-machine class per axis, under row lock | Proposed |
| 016 | Payment: provider-independent contract; paid only after server-to-server verify; raw events unique | Proposed |
| 017 | Outbox + idempotency keys for critical events and POSTs | Proposed |
| 018 | COD as first-class payment method with remittance reconciliation | Proposed |
| 019 | Booking overlap: `UNIQUE(active_resource_id, period WITHOUT OVERLAPS)` — verified by spike S1 (MariaDB-only) | Proposed |
| 020 | Search: MariaDB FULLTEXT + normalisation + synonyms behind `CatalogSearch`; external engine only on measured trigger (spike S2) | Proposed |
| 029 | Payments MVP: COD + SSLCommerz aggregator (incl. bKash via SSLCommerz); direct bKash adapter later after official docs verified | Accepted (owner) |
| 030 | Tax: configurable engine (rates as dated data; inclusive/exclusive, shipping/COD-fee taxability and enable flag are settings); owner not yet VAT-registered, will register | Accepted (owner) |
| 028 | No `vendor_id` in MVP tables (owner took recommendation); marketplace needs its own migration + commission/settlement design | Accepted (owner) |
| 021 | Auth: sessions for web, Sanctum tokens for mobile; admin guard with mandatory TOTP; RBAC via spatie/laravel-permission [UNVERIFIED: evaluate] | Proposed |
| 022 | Admin = custom Blade, not Filament (BD-08) | Proposed |
| 023 | Redis for cache/queue/session; Horizon (BD-04) | Proposed |
| 024 | Object storage: public media bucket + private digital bucket (BD-05). Dev uses SeaweedFS because MinIO no longer publishes public images; verified: private objects 403 anonymously, signed URLs work and expire | Proposed |
| 025 | API: `/api/v1`, problem+json, Idempotency-Key, OpenAPI 3.1 in CI (BD §12) | Proposed |
| 026 | Hierarchies (categories, geography) via closure table (no ltree on MariaDB) | Proposed (new) |
| 027 | Migrations: small single-purpose, forward-only, expand→migrate→contract (DDL not transactional) | Proposed (new) |

## Detail for the new/changed ADRs

### ADR-014 Isolation
Context: InnoDB default REPEATABLE READ takes gap locks, increasing deadlocks on conditional updates/inserts. Options: keep RR; READ COMMITTED; SERIALIZABLE. Decision: READ COMMITTED for write transactions in Orders/Payments/Inventory/Coupons. Consequence: non-repeatable reads inside a transaction must be handled by explicit `FOR UPDATE`. Reversal: connection-level setting.

### ADR-026 Closure tables
Categories `category_closure(ancestor_id, descendant_id, depth)`; same for geography if needed. Writes more costly, reads trivial; categories change rarely.

### ADR-013 Stock commit point
Accepted: reserve at order creation, `on_hand` decremented at shipment (commit of reservation).

### ADR-019/020
Evidence in `docs/spikes/S1_S2_MARIADB_SPIKES.md`. ADR-019 locks production to MariaDB.

### ADR-028 No vendor_id
Adding a nullable column later is cheap on MariaDB; the hard part of a marketplace (commissions, settlements, vendor payouts) is a design problem a column now would not solve.

### ADR-001 addendum — dependencies added in Phase 1 (reason, maintenance, licence)
| Package | Why | Notes |
|---|---|---|
| laravel/framework 13.39 | the framework (owner-approved) | MIT, first-party |
| pestphp/pest 4.7 + pest-plugin-laravel | test runner with architecture expectations | MIT; replaces plain PHPUnit syntax; PHPUnit 12 underneath |
| larastan/larastan 3.13 | static analysis level 8 | MIT; widely used |
| laravel/pint 1.32 | code style | MIT, first-party |
| laravel/horizon 5.50 | queue monitoring for Redis queues (BD-04) | MIT, first-party; dashboard locked to local until Phase 2 |
| laravel/pao (from skeleton) | compact test/tool output for AI agents | shipped with the Laravel 13 skeleton; removable |
| alpinejs | light interactivity (BD-03) | MIT |
| @fontsource-variable/inter, @fontsource/noto-sans-bengali | self-hosted fonts (BD-36), no CDN | OFL-licensed fonts |
| league/flysystem-aws-s3-v3 3.x | S3 disks (media + private) | MIT, first-party Laravel integration |
| SeaweedFS (container, dev only) | local S3-compatible storage; MinIO images unavailable | Apache-2.0; not a code dependency |
| tailwindcss 4 / vite 8 / laravel-vite-plugin | asset pipeline (BD-03) | from skeleton |
`composer audit` and `npm audit --audit-level=high` were clean on 2026-10-28.

### ADR-001 addendum — dependencies added in Phase 3
| Package | Why | Notes |
|---|---|---|
| intervention/image 4.x (GD driver) | re-encode uploads, resize, WebP/AVIF | MIT; actively maintained; GD is bundled with PHP so no ImageMagick needed |
| ezyang/htmlpurifier | sanitise product / category HTML at write time | LGPL-2.1+; long-established, standards-based allow-list. Used directly instead of the `mews/purifier` wrapper (one less dependency; same engine) |
### ADR-001 addendum — dependencies added in Phase 4
| Package | Why | Notes |
|---|---|---|
| @playwright/test 1.64 (dev only) | real-browser tests: keyboard-only, no-JavaScript, fonts, consent flow; the Phase 12 E2E suite builds on it | Apache-2.0, Microsoft, very actively maintained. An earlier pin (1.49) was flagged by `npm audit` (GHSA-7mvr-c777-76hp), so the current release is used. Browsers are downloaded by `playwright install`, only in dev/CI. |
| @axe-core/playwright 4.14 (dev only) | automated WCAG 2.0/2.1/2.2 A+AA scan of every key template in both languages | MPL-2.0, Deque; the same engine Lighthouse uses |
| (not added) Lighthouse CI via `npx @lhci/cli` | performance budgets | run on demand in CI, not installed in the project |
Phase 4 also added tables `cms_pages`, `cms_page_translations`, `banners`, `banner_translations`, `slug_redirects`, `catalog_listing` (storefront read model), `search_index`, `search_skus`, `search_terms`, `search_synonyms`, `search_queries`, `analytics_events`.

Phase 3 also added `catalog_imports` (CSV import job status; not in the original data dictionary).

### ADR-001 addendum — dependencies added in Phase 5
None. Cart, addresses, shipping, pricing engine, coupons, orders, checkout, the COD rules and the admin screens use only the framework, the packages already approved, and the project's own modules. `composer audit` and `npm audit` unchanged.

### Phase 5 decisions and deviations from the draft design (cart, checkout, orders)
Owner-approved on 2026-10-29 unless noted.

| # | Decision | Why / consequence |
|---|---|---|
| P5-1 | **Order number `VM-YYMM-NNNNNN`** from a database SEQUENCE (month in Dhaka time, six-digit counter that never resets), replacing the `ORD-` format in DATABASE_DESIGN | the shop's prefix; unique under concurrency (12 processes, 12 numbers); gaps after a rolled-back order are acceptable |
| P5-2 | **No separate Checkout module**: `PlaceOrder` and the checkout pages live in Orders. Idempotency is a UNIQUE `orders.placement_key` (sha256 of shopper + the form's one-time token) rather than the `idempotency_keys` table | one transaction owns order + stock + coupon + cart + outbox, so a module boundary in the middle would only add contracts; the table-based `idempotent` middleware stays for the API |
| P5-3 | **Stock is released when an order is cancelled by a direct contract call in the same transaction** (`Inventory\StockReservations`), not by an `OrderCancelled` event | atomic: a cancelled order can never keep stock; an event would add a window where it does |
| P5-4 | **COD orders start `pending`** (flag `ORDERS_AUTO_CONFIRM_COD` to confirm at once); a flagged order is never auto-confirmed and cannot be confirmed until a person clears the review | phone confirmation is how COD is run in Bangladesh |
| P5-5 | **Cash-order abuse rules** (settings `fraud.*`, editable in admin): blocked phone/address refused without saying why; ≤ 3 open COD orders per phone; COD above ৳10,000 needs an SMS code; many orders per phone per day and one address used by many phones are placed but held (`needs_review`). The open-order count is made safe by a per-phone `GET_LOCK` held around the order transaction | a count cannot be protected by a row lock because the new row does not exist yet; proven with 8 parallel processes → exactly 3 orders, and 0 protection (8 orders) with the lock removed |
| P5-6 | **Block list stores only hashes** (sha256 of the E.164 phone or of a normalised address fingerprint) plus a masked hint; the list is not a second copy of customer data | privacy; matching is exact on normalised values |
| P5-7 | **One discount coupon + one free-delivery coupon per order**, plus the single best automatic promotion; at most two codes | decided in the Phase 5 plan |
| P5-8 | **Orders are snapshots and frozen by the database**: money, identity and time cannot be UPDATEd (trigger lists the frozen columns; only the three states, review flag and cancellation fields change); no deletes; history, notes, tax lines and fraud flags are append-only | audit trail; "totals reproducible from stored rows" is a test over 250 random orders |
| P5-9 | **Cash on delivery is the only payment method until Phase 6**; carts with only downloads or services cannot be checked out yet (the page says so). `PaymentInitiator` is a stub contract with a COD implementation | online payments need the verified SSLCommerz docs (`docs/vendor/`) which are not saved yet |
| P5-10 | **SMS goes through the log adapter** until SSL Wireless documentation is saved under `docs/vendor/sslwireless/` | AGENTS.md: use only endpoints present in saved official docs |
| P5-11 | **Geography data is the MIT-licensed `nuhil/bangladesh-geocode` set** (8 divisions, 64 districts, 494 upazilas; official count is 495); source and date recorded in `app/Modules/Customers/Database/data/geography/SOURCE.md` | not an official BBS file; owner to confirm or replace |
| P5-12 | **VAT stays disabled by default** (`tax.enabled=false`); the engine supports inclusive and exclusive prices, tax on delivery and the COD fee, and snapshots rates on order lines | owner is not VAT-registered; rates need the tax adviser |

New tables in Phase 5 (all in the data dictionary): `divisions`, `districts`, `upazilas`, `customer_addresses`, `carts`, `cart_items`, `cart_coupons`, `shipping_zones`, `shipping_zone_translations`, `shipping_zone_districts`, `shipping_methods`, `shipping_method_translations`, `shipping_rates`, `coupons`, `coupon_products`, `coupon_categories`, `coupon_redemptions`, `promotions`, `promotion_translations`, `orders`, `order_items`, `order_addresses`, `order_status_history`, `order_tax_lines`, `order_notes`, `order_fraud_flags`, `order_blocklist`; plus the `order_number_seq` sequence and the OTP purpose `checkout_cod`.

### ADR-001 addendum — dependencies added in Phase 6
None. Payments, refunds, cash on delivery, reconciliation, the admin screens and the customer pages use only the framework, the packages already approved and the project's own modules. The SSLCommerz adapter talks to the gateway with Laravel's HTTP client. `composer audit` and `npm audit` unchanged.

### Phase 6 decisions and deviations from the draft design (payments, refunds, COD, reconciliation)
Owner-approved on 2026-10-30 ("go with recommendations") unless noted.

| # | Decision | Why / consequence |
|---|---|---|
| P6-1 | **One "Pay online" button** that opens the SSLCommerz hosted page; cash on delivery stays. bKash is reached through SSLCommerz, no direct bKash/Nagad adapter | one integration to prove and operate; the customer picks bKash/card on the hosted page |
| P6-2 | **A payment becomes paid only in `VerifyPayment`**, server to server, matching reference, amount, currency and the bank transaction id, under the payment row lock. Return URLs and notifications are hints that trigger a check | never believe the browser or an unauthenticated notification |
| P6-3 | **Orders follow payments only through the outbox** (`payments.*` events handled by `Orders\Listeners\ApplyPaymentEvent`); Payments never writes order tables | module boundary; exactly-once effect through `IdempotentConsumer` |
| P6-4 | **Payment expires after 30 minutes**; before expiring, the gateway is asked one last time; a success that arrives after expiry is recorded and sent to a person (`needs_review`), **never auto-refunded** | money is never silently lost; the owner decides |
| P6-5 | **Closed-unpaid attempts (failed, cancelled, expired) can still become paid for 7 days** (a gateway may report failed and later success under one transaction id); after that, the settlement check catches them | found by the replay suite; migration 2026_10_02_000003 |
| P6-6 | **A second paid attempt on one payment is a `duplicate_payment` exception, refundable within that attempt's own amount**; the order is not double-paid | parallel attempts on one order |
| P6-7 | **A gateway risk flag (`risk_level=1`) holds the order** (paid, `needs_review`) instead of confirming it | the gateway asks the shop to check the customer first |
| P6-8 | **Refunds: request → approve → send, or complete by hand with proof.** Above ৳5,000 (setting `payments.refund_second_approver_minor`) a different person must approve. Partial refunds are manual records until a sandbox proves the gateway supports them. Gateway fees are absorbed by the shop | the owner's rule; never trust an unproven adapter with partial money |
| P6-9 | **Over-refund is impossible**: one conditional `UPDATE … refunded_minor + ? <= captured_minor` under the payment lock, with a CHECK as backstop; reserved = requested, approved, processing, succeeded | proved by multi-process tests |
| P6-10 | **A refund sent to the gateway carries our own unique `refund_ref`**; "accepted" stays `processing` until the gateway says refunded; an unreachable gateway repeats the same call after 60 seconds; stuck for 3 days raises `refund_stuck` | safe retries; no double refund |
| P6-11 | **Cash collected becomes a normal paid payment** (provider `cod`, one succeeded attempt) so refunds and reports treat it like any money; amount comes from the order; one hand-recorded collection per order (generated-column UNIQUE), shipment collections stay one per shipment | uniform ledger; cannot be mistyped |
| P6-12 | **Courier statements are checked against OUR collections**, not the statement's own claim; short, over or per-parcel differences become exceptions | the courier's total is a claim, ours is the ledger |
| P6-13 | **A settlement file is never proof of payment**: a line that says paid while we show unpaid is put to the gateway and only its answer counts. The CSV format is provider-neutral (`reference,bank_txn_id,amount,currency,settled_at`) because no saved document describes SSLCommerz's real report | [UNVERIFIED] mapping to the real report needs its documentation saved first |
| P6-14 | **Online payments can be paused** (setting `payments.online_frozen`) by finance in seconds; cash stays; in-flight payments still reconcile | gateway trouble must not cost orders |
| P6-15 | **Signals and alerts** (callback failure rate, payments open too long, amount/currency mismatches, refund failures, notifications that failed the gateway's own check) are logged as ERROR `payments.alert` once an hour each and shown on the finance pages | monitoring alerts on the log line; thresholds in `config/payments.php` |
| P6-16 | **A guest order can be added to an account once** (database trigger: owner set once, never changed); the signed confirmation link is the proof of ownership; sign-up from that page answers identically for new and existing addresses | no account probing; no taking an order from one account to another |
| P6-17 | **Per-person, per-route rate limits for the money screens** (named limiters) instead of `throttle:N,M`, which shares one counter per person across all routes | unrelated screens must not eat each other's allowance |
| P6-18 | **The live gateway is reachable only when production, sandbox off and `PAYMENTS_ALLOW_LIVE=true` all hold** | a development machine with live credentials cannot reach the bank |

### Phase 7 decisions and deviations from the draft design (parcels, couriers, returns, inventory operations, courier statements)

| # | Decision | Why / consequence |
|---|---|---|
| P7-1 | **Stock leaves the warehouse when a parcel is made, for the quantities in it only** (ADR-013), never more than ordered; the order row is the lock that also guards cancellation | one place decides whether goods have gone; proved by multi-process tests |
| P7-2 | **A parcel's state is the latest courier event by the courier's own time**, mapped through a status table; events are stored once and never changed; a word we do not know is kept and raised to the delivery-problems queue instead of guessed | couriers send events late and out of order; an unread word must never move money or stock |
| P7-3 | **Webhook and polling feed the same Action**; the manual courier carries the phase until a real adapter exists | no second code path to keep in step; the real adapter (E7-3) is blocked until the courier's official documents are saved in `docs/vendor/<courier>/` |
| P7-4 | **Cash on delivery is split exactly across parcels** and recorded once per parcel when it is delivered (a partly paid order is `partially_paid`); a delivered and paid order completes by itself | no rounding differences; the order's money always adds up |
| P7-5 | **Failed delivery: 3 attempts, then a person decides** (setting `shipping.max_delivery_attempts`); a parcel that comes back opens a return of everything in it, once | the shop, not the courier, decides what happens next |
| P7-6 | **Returns: ask → allow or refuse → count what came back → look at each item (restock, quarantine, write off)**; the return window is 7 days (setting `shipping.return_window_days`), staff may start one any time; postage payer is recorded, never charged; **a refund is only suggested, never made**, by Shipping | refunds stay in Payments behind their own approvals |
| P7-7 | **Quarantine is a stock place that is never for sale and never reserved**; write-offs count the unit in and out so the ledger shows them | nothing disappears without a ledger row |
| P7-8 | **Transfers take stock out at once (only what is not promised to orders) and add it at the other place when received; what did not arrive is recorded as lost**; cancelling puts it back | stock is never in two places or in none |
| P7-9 | **A stock-take is approved by a different person than the one who handed it in** (database trigger plus the Action), one open count per place (UNIQUE), and a correction that would take stock below what is promised changes nothing | a single person cannot hide a loss |
| P7-10 | **Courier statements are checked against our own parcels and cash**: received plus the courier's fee must equal the cash we collected; each line is matched or becomes a problem; the same file twice is refused; the CSV format is provider-neutral (`consignment_id,status,cod_amount,courier_fee,delivered_at`) until a courier's real format is saved | the courier's total is a claim, our ledger is the proof |
| P7-11 | **Customers track an order through a link we signed and that expires** (confirmation page, "my orders"); the page shows stages in our own words, never the address, phones, the courier's own text or any internal id; the courier's link is shown only if it is plain https; the page is `noindex` and offers each language through a link signed for it with the same expiry. The draft's "order number plus phone" lookup is **not built** | a lookup by number would let anyone probe orders |
| P7-12 | **Staff screens call the same Actions as everything else**; each action needs its own permission (`shipping.view/create_shipment/manage`, `inventory.view/transfer/count/adjust`, `payments.reconcile` for importing a statement, `settings.manage` for the delivery rules) and the routes refuse it even when the button is hidden | deny by default; tested as a matrix |
| P7-13 | **Delivery signals** (courier words we cannot read, parcels with no news for 7 days, parcels made but not handed over for 48 hours, problems open for 48 hours, returns undecided for 72 hours) are logged as ERROR `shipping.alert` once an hour each and shown on the parcel and problem screens | the same pattern as the payment signals (P6-15) |
| P7-14 | **Printable HTML, no PDF library** (agreed in the Phase 7 plan): an order receipt, a packing slip per parcel and a pick list are built; **invoices, credit notes, bulk create and bulk label printing are not** (see the backlog) | a PDF library needs a Bangla font decision and a written reason (ADR-001) |
| P7-15 | **The receipt is titled "Order receipt" and says it is not a VAT invoice** until a tax adviser confirms the format; it is built from stored figures and refused to customers if they do not add up; customers reach it only through a signed, expiring link | the owner is not VAT-registered; a wrong figure on a paper is worse than no paper |

### Phase 8 decisions (digital products and service bookings)

| # | Decision | Why / consequence |
|---|---|---|
| P8-1 | **Two new modules: `DigitalProducts` and `Bookings`** (the brief said "Services"; that name would collide with the `Services/` folder every module has). Services never touch `stock_*` tables; digital goods never create shipments | boundaries stay readable; both rules have tests |
| P8-2 | **Digital goods are prepaid only, services are prepaid in full at booking**; cash on delivery is never offered when the cart has a digital or service line. Deposits and "pay on the day" are backlog | Q12 assumption; the online payment method must be switched on (sandbox credentials needed) |
| P8-3 | **A digital file is stored only in the private bucket, under a random key made by us; every upload is a new immutable version** with its content type read from the file's own bytes, size and SHA-256; allowed kinds are an allow-list (pdf, epub, zip, mp3, png, jpg), at most 200 MB (setting `DIGITAL_MAX_UPLOAD_MB`), with a virus-scan hook only | the name and the browser's claim are not trusted; nothing from the upload is in the stored name |
| P8-4 | **No code path exposes a permanent public URL for a digital file**; customers get short-lived signed addresses only after an entitlement check | acceptance criterion; tested |
| P8-5 | **Rights to download are made by an `OrderPaid` event** (Orders, outbox, once per order), one right per digital order line; the grant is idempotent by a database UNIQUE and by the outbox consumer; the right is granted even before the file exists | a paid order must never lose its right because staff upload late |
| P8-6 | **An entitlement carries no owner**: the owner is the order's owner, looked up through Orders when needed | a guest order can be claimed by an account later (P6-16); a copy would go stale |
| P8-7 | **Cash on delivery is refused in code (not data) for any cart with a digital or service line**; the downloads-used counter can never pass the limit (database CHECK); "expired" is derived from `expires_at`, not stored | prepaid-only (P8-2) must not depend on how a payment method row is configured |
| P8-8 | **A download is a POST that answers with a 303 to a signed storage address valid for about 60 seconds**, not a stream through PHP; the address is never stored, logged or rendered in a page; the counter, the address and the log row are one transaction | large files must not depend on PHP time and memory; a link preview must not use up a download |
| P8-9 | **One conditional UPDATE is the whole limit check** (active, not expired, under the limit) and a database CHECK backs it; a refused attempt never uses a download and is always logged; someone else's download is "not found" and logged as `not_owner` | parallel downloads can never pass the limit; probing is remembered but not confirmed |
| P8-10 | **Guest access = a link we signed + an SMS code to the order's phone + a 30-minute session for that one order** (the existing code service, purpose `guest_access`); every wrong proof reads the same | guests have no account to prove ownership with |
| P8-11 | **Download logs keep IP and browser for now; pruning after 12 months (security notes) is a later job**, because the table is append-only and a pruning exception needs its own decision | known gap |
| P8-12 | **Licence keys: one per unit bought, given when the order is paid, lowest first, with `FOR UPDATE SKIP LOCKED` plus a guarded update; the right is kept when the pool is short and filled automatically, oldest order first, on the next import and by a scheduled check** | the sale is never lost to a stock-out of keys, and no key is ever given twice |
| P8-13 | **Keys are encrypted with the app key, found by an HMAC fingerprint, shown only on request on a `no-store` page, and never logged or audited in the clear (the audit trail holds counts and key ids)**; they stay visible after downloads run out or time passes, but never after a right is revoked | the customer bought them; losing `APP_KEY` makes stored keys unreadable, so it is part of the backup runbook |
| P8-14 | **Refund policies are data, not code**: three rules, an optional number of days, a switch for withdrawing downloads and keys on a full refund, one default and a per-product choice; their words (English required, Bangla falls back to it) are what customers read on the product page and at checkout | all policy texts shown to customers come from configurable, translated records |
| P8-15 | **A full refund withdraws the order's downloads and keys, as each policy says; a partial refund changes nothing automatically; chargebacks are manual until Payments exposes them**; the policy's verdict is advice shown to staff, and the refund itself stays in Payments behind its own approvals | a partial refund does not say which line it covers; Payments is not changed in this phase |
| P8-16 | **A new version is announced once (`DigitalAssetVersionPublished`) and each owner with an email is told once per version** (database UNIQUE); a failed send is taken back and retried; owners with no email see it in their library | no spam, no lost notice |

