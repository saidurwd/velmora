# 02 — Baseline Architecture Decisions (pre-ADRs)

> **⚠ OVERRIDE (2026-10-28):** Owner chose **MariaDB/MySQL, not PostgreSQL**. See `docs/architecture/DATABASE_ENGINE_DECISION.md` (ADR-000). Every PostgreSQL-specific item below (BD-02, `timestamptz`, `tstzrange` EXCLUDE, partial indexes, `tsvector`/`pg_trgm`, `ltree`, `btree_gist`) is superseded by the replacement table in that ADR.

**Purpose.** Your Draft.md asks the AI to *decide* almost everything. That is how architecture drifts between sessions. This file **pins the decisions that are expensive to reverse** so every AI session starts from the same foundation. The AI must record each one as an ADR in Phase 1, may *challenge* any of them with evidence, but must not silently deviate.

**Status legend:** 🔒 pinned (change only via ADR + human approval) · 🔎 verify first (confirm against current official docs before use) · 💡 default (AI may propose better, with justification).

Attach this file to **every** prompt in the pack.

---

## 1. Stack

| # | Decision | Status |
|---|---|---|
| BD-01 | Laravel latest stable at project start, PHP version = newest supported by that Laravel release and your host. Record exact versions in ADR-001. | 🔎 |
| BD-02 | PostgreSQL latest stable major supported by your hosting (≥ 16). Tests run on **PostgreSQL, never SQLite** (we rely on exclusion constraints, partial indexes, row locks). | 🔒 |
| BD-03 | Blade + Tailwind CSS + Alpine.js via Vite. No SPA framework, no Livewire/Inertia unless an ADR shows a requirement Blade+Alpine cannot meet. | 🔒 |
| BD-04 | Redis for cache, sessions (optional), queues; Laravel Horizon for queue monitoring. | 💡 |
| BD-05 | S3-compatible object storage (public bucket for catalog media, **private** bucket for digital goods and KYC/attachments). Local MinIO in dev. | 🔒 |
| BD-06 | Pest + Larastan (level ≥ 8) + Laravel Pint + Pest architecture tests. Playwright for E2E. k6 for load. | 💡 |
| BD-07 | Docker Compose for local dev (php-fpm, nginx, postgres, redis, minio, mailpit). GitHub Actions CI. | 💡 |
| BD-08 | Admin panel = custom Blade + shared Blade component library. Evaluate Filament in an ADR, but default is *not* adopting it (keeps one UI paradigm and full control of workflows). | 💡 |

## 2. Code organisation (modular monolith)

**BD-10 🔒** One Laravel app. Business code lives in `app/Modules/<Module>/`:

```
app/Modules/Orders/
  Actions/            # one class = one use case (PlaceOrder, CancelOrder)
  Contracts/          # interfaces other modules may depend on
  DTOs/  Enums/  Events/  Listeners/  Jobs/  Policies/  Exceptions/
  Models/             # Eloquent models owned by this module only
  Http/Controllers/{Web,Admin,Api}/  Http/Requests/  Http/Resources/
  Providers/OrdersServiceProvider.php
  routes/{web,admin,api}.php
  resources/views/  resources/lang/{en,bn}/
  database/migrations/
  tests/{Unit,Feature}/
```

**BD-11 🔒 Dependency rules** (enforced by Pest arch tests / Deptrac in CI):
1. A module may use **another module's `Contracts/`, `DTOs/`, `Enums/`, `Events/` only** — never its Models, Actions or tables.
2. Cross-module writes happen through **domain events** (after-commit) or a contract method — never by touching another module's tables.
3. Controllers are thin: validate (FormRequest) → authorize (Policy) → call one Action → return Resource/View.
4. Business rules live in Actions/domain classes, **never** in controllers, Blade, or Eloquent observers.
5. Web, Admin and API controllers call the **same Actions**. No duplicated rules.
6. No generic Repository layer over Eloquent. Query objects/scopes are fine.

**BD-12 🔒 Module map and allowed dependency direction**

```
Platform (shared kernel: Money, Locale, Clock, Audit, Idempotency, Outbox, Settings)
   ▲ every module may depend on Platform
IdentityAccess   Customers   Cms   Notifications   Integrations   Reporting(read-only)
Catalog ──► Pricing/Promotions ──► (nothing)
Inventory ──► Catalog(contracts)
Cart ──► Catalog, Pricing, Inventory(availability read)
Checkout ──► Cart, Pricing, Shipping(quote), Inventory(reserve), Orders(create), Payments(initiate)
Orders ──► Catalog(snapshot data via DTO)         # Orders NEVER calls Payments
Payments ──► Orders(contract: OrderPayable)       # Payments → event PaymentSucceeded → Orders listens
Shipping ──► Orders(contract) ; listens OrderConfirmed
DigitalProducts, Services(Bookings) ──► Catalog, Orders(contract); listen OrderPaid/OrderConfirmed
Reviews/Engagement ──► Catalog, Orders(contract)
```

## 3. Data conventions

| # | Decision | Status |
|---|---|---|
| BD-20 | **Money = `bigint` minor units (poisha) + `currency` char(3)** on every monetary column. A `Money` value object in Platform; **no floats, no `decimal` math in PHP**. Display via locale-aware formatter (৳ 1,250.00 / ৳ ১,২৫০.০০). | 🔒 |
| BD-21 | Every table: `id bigint` PK (internal) + `public_id` ULID (API, URLs, emails). Never expose auto-increment IDs. | 🔒 |
| BD-22 | Timestamps stored in UTC (`timestamptz`); displayed in `Asia/Dhaka`. | 🔒 |
| BD-23 | **Soft deletes** only for catalog/CMS/customer-facing content. **Financial and inventory records are append-only** (no update/delete of ledger rows; corrections are new rows). | 🔒 |
| BD-24 | Enums stored as `varchar` + `CHECK` constraint (not native PG enum — easier migrations) backed by PHP enums. | 💡 |
| BD-25 | Foreign keys everywhere; `ON DELETE RESTRICT` by default. Explicit `CHECK` for quantities ≥ 0, money ≥ 0, valid status values. | 🔒 |
| BD-26 | JSONB only for provider payloads/metadata and genuinely free-form attributes. Anything queried/filtered/joined is a real column. | 🔒 |
| BD-27 | Order lines **snapshot** name, SKU, unit price, tax, variant options, tax-rate at purchase time. Orders never re-read live catalog prices. | 🔒 |
| BD-28 | Order numbers from a DB sequence, formatted `ORD-YYMM-NNNNNN`; public lookup always requires number **and** phone/email or signed token. | 💡 |
| BD-29 | Audit: `audit_events(actor_type, actor_id, action, subject_type, subject_id, before, after, ip, user_agent, request_id, created_at)` for every admin write and every financial/inventory state change. | 🔒 |

## 4. Localisation (English + Bangla)

| # | Decision | Status |
|---|---|---|
| BD-30 | Translatable content uses **normalised translation tables**: `<entity>_translations(<entity>_id, locale, name, slug, description, seo_title, seo_description, …)` with `UNIQUE(<entity>_id, locale)` and `UNIQUE(locale, slug)`. Rationale: indexable, FTS-friendly, enforceable constraints. JSONB-per-column rejected (cannot index/constrain per locale cleanly). | 🔒 |
| BD-31 | `languages` table (code, name, native_name, is_default, is_active, direction) → adding a language is data + a lang folder, not a code change. | 🔒 |
| BD-32 | Fallback chain `bn → en` (and `en` is mandatory content; `bn` may be incomplete but admin UI flags missing translations). | 💡 |
| BD-33 | Locale in URL prefix (`/en/…`, `/bn/…`), `hreflang` + canonical tags, locale remembered in cookie/user profile, `/` redirects by cookie → Accept-Language → default. API uses `Accept-Language`. | 💡 |
| BD-34 | UI strings in `lang/{en,bn}` JSON/PHP files; CI test fails if any key exists in one locale and not the other, or if a view contains hard-coded user-visible text. | 🔒 |
| BD-35 | Bangla digits are a **display option only**. Store ASCII digits. Input normalisation converts ০–৯ → 0–9 for phone, quantity, price, OTP, postal code fields. | 🔒 |
| BD-36 | Fonts: self-hosted, subset **Noto Sans Bengali** (or Hind Siliguri) + a Latin UI font; `font-display: swap`; `lang` attribute set per page; line-height tuned for Bangla (≥ 1.6). PDFs/emails must embed a Bangla-capable font. | 🔒 |
| BD-37 | Unicode normalisation (NFC) on all text input and search queries. Collation/sort tested with Bangla strings. | 🔒 |

## 5. Catalog model

**BD-40 🔒 Sellable unit = `product_variants`.** Every product has ≥ 1 variant (a "simple" product has one default variant). Cart, orders, stock and prices reference `variant_id`.

```
products(id, public_id, product_type[physical|digital|service], brand_id, status, tax_class_id, …)
product_translations(product_id, locale, …)
product_variants(id, product_id, sku UNIQUE, barcode, is_default, track_inventory, weight_g, length_mm, width_mm, height_mm, …)
variant_option_values / attributes / attribute_values (+ translations)
prices(variant_id, currency, amount_minor, compare_at_minor, starts_at, ends_at, price_list_id NULL)
categories(+translations, closure or ltree for hierarchy) · brands(+translations) · collections · tags
product_media(product_id|variant_id, disk, path, alt per locale, sort)
```

Type-specific extension tables (1:1 with `products` or `variants`): `digital_assets`, `service_definitions`. **Product type drives fulfilment rules, not the table the product lives in.**

| Type | Inventory | Fulfilment | Refund rule |
|---|---|---|---|
| physical | `stock_levels` per location | Shipment via courier | Return window, condition check |
| digital | none (license-key pool optional) | Entitlement created on `OrderPaid` | Restricted once downloaded (policy configurable) |
| service | capacity/slots (not stock) | Booking → confirmed → performed → completed | Cancellation windows by policy |

## 6. Inventory & concurrency (🔒 all)

```
stock_locations(id, name, type)
stock_levels(variant_id, location_id, on_hand, reserved, PK(variant_id, location_id),
             CHECK on_hand >= 0, CHECK reserved >= 0, CHECK reserved <= on_hand)
stock_movements(id, variant_id, location_id, delta, reason, ref_type, ref_id, created_at)   -- append-only ledger
stock_reservations(id, order_or_cart_ref, variant_id, location_id, qty, status[active|committed|released|expired], expires_at)
```

* **Reserve atomically** in one transaction with a single conditional statement:
  `UPDATE stock_levels SET reserved = reserved + :q WHERE variant_id=:v AND location_id=:l AND on_hand - reserved >= :q` → 0 rows updated = insufficient stock. No read-then-write.
* Multi-line orders lock/update variants **in ascending `variant_id` order** to avoid deadlocks. Retry on `40001`/`40P01` (bounded).
* Reservation TTL (default 20 min online-payment, configurable); scheduler expires and releases. COD orders reserve at order creation and auto-cancel if unconfirmed after N hours (configurable).
* Commit reservation → decrement `on_hand` + ledger row on payment success/COD confirmation/shipment (decide in ADR; default: commit at shipment, reserved from order).
* Every stock change writes a `stock_movements` row; `on_hand` must always equal the sum of movements (nightly integrity check job).

## 7. Orders, payments, refunds (🔒 all)

**Three independent status axes on `orders`** (plus per-shipment and per-payment statuses):

| Axis | Values |
|---|---|
| `status` (lifecycle) | `pending` → `confirmed` → `processing` → `completed` ; `cancelled` (from pending/confirmed/processing before shipment) |
| `payment_status` | `unpaid` · `pending` · `paid` · `partially_refunded` · `refunded` · `failed` |
| `fulfillment_status` | `unfulfilled` · `partially_fulfilled` · `fulfilled` · `partially_returned` · `returned` |

* Allowed transitions live in **one** state-machine class per axis; every transition writes `order_status_history` (+ audit event) and is executed under `SELECT … FOR UPDATE` on the order row (prevents cancel-vs-fulfil races).
* **Payment objects:** `payments` (one per payable attempt group) → `payment_transactions` (each gateway interaction) → `payment_webhook_events` (raw inbound, `UNIQUE(provider, provider_event_id)`) → `refunds`.
* **Payment transaction status:** `initiated → pending → succeeded | failed | cancelled | expired`. Terminal states never regress.
* **Gateway contract** (Payments/Contracts): `initiate()`, `verify()` (server-to-server), `parseCallback()`, `refund()`, `status()`, `capabilities()`. Capabilities are *declared per provider and verified from docs* — never assumed.
* **Never mark paid from the browser redirect.** Success only after server-to-server verification that **amount, currency, merchant reference and status** all match the payment record.
* Callback handling: store raw event → verify authenticity → `FOR UPDATE` on payment → idempotent transition → dispatch `PaymentSucceeded` after commit. Duplicate callbacks are no-ops returning 200.
* **Refund guard:** under lock on the payment, `sum(refunds in requested|processing|succeeded) + new ≤ captured amount`.
* **COD** is a first-class payment method: `cod_pending_collection → cod_collected (by courier) → cod_remitted` + reconciliation per courier settlement. COD fee and COD eligibility (zone, order value, fraud score) are configurable rules.
* **Idempotency:** `idempotency_keys(key, scope, request_hash, response, expires_at)`; required on API `POST /checkout`, `/payments`, `/refunds`, and honoured on Web via form tokens.
* **Reconciliation job** (scheduled): finds `pending` payments older than N minutes → calls provider `status()` → resolves; produces an exceptions report.
* Domain events are dispatched **after commit**; critical ones go through an **outbox** table (`outbox_messages`) so a crash between commit and dispatch cannot lose `PaymentSucceeded`.

**Coupons:** `UPDATE coupons SET used_count = used_count + 1 WHERE id=:id AND (usage_limit IS NULL OR used_count < usage_limit)` inside the order transaction; `coupon_redemptions UNIQUE(coupon_id, order_id)`; per-customer limits checked under coupon row lock.

## 8. Services & bookings (🔒)

* `service_definitions`, `service_packages`, `service_resources` (staff/team/room), `availability_rules` (weekly + exceptions), `service_bookings`.
* Double-booking prevented **by the database**: `EXCLUDE USING gist (resource_id WITH =, tstzrange(starts_at, ends_at) WITH &&) WHERE (status IN ('held','confirmed'))` (requires `btree_gist`). Application logic is a convenience; the constraint is the guarantee.
* Booking lifecycle: `held (TTL) → confirmed → in_progress → completed` ; `cancelled`, `no_show`, `rescheduled`. Cancellation/refund windows come from policy rows, not code.

## 9. Digital products (🔒)

* Files in the **private** bucket only. Download = authenticated controller → ownership + entitlement check → short-lived signed URL or streamed response. No public file URLs, ever.
* `entitlements(user_or_guest, order_item_id, asset_id, download_limit, downloads_used, expires_at, status)`; `download_logs` (ip, ua, time). Optional `license_keys` pool (allocated once, atomically).
* Guest buyers receive a signed, expiring access link by email/SMS.

## 10. Shipping & Bangladesh geography

* Geography tables: `divisions`, `districts`, `upazilas` (and optionally `unions`/`areas`) with `name_en`, `name_bn`, codes. 🔎 Seed from an authoritative source (BBS / Local Government Division dataset) and record the source + date in the seed file header.
* `shipping_zones` map to district/upazila sets (at least: *Inside Dhaka*, *Dhaka suburbs*, *Outside Dhaka*, plus custom zones), `shipping_methods`, `shipping_rates` (weight bands, order-value bands, free-shipping thresholds), COD surcharge rule.
* `shipments`, `shipment_items` (partial fulfilment), `shipment_events` (append-only), `courier_accounts` (credentials encrypted; per-provider config).
* **Courier contract**: `createShipment()`, `cancelShipment()`, `track()`, `parseWebhook()`, `rateQuote()` (if supported), `codSettlement()` (if supported). 🔎 Candidate providers: Pathao, Steadfast, RedX, Paperfly — verify API availability, onboarding and COD settlement semantics before committing.
* Failed delivery → retry (N attempts) → return-to-origin → inventory receive-back workflow.

## 11. Identity & access

* Web: Laravel session auth. Mobile/API: Sanctum personal access tokens (abilities, device name, expiry, revoke-all, refresh/rotation policy in ADR).
* **Phone-first identity** (common in Bangladesh): register/login by email *or* BD mobile number with **OTP** via an `SmsGateway` contract (provider chosen later 🔎). OTP: hashed at rest, 5-min expiry, attempt limits, resend throttling, per-number and per-IP rate limits.
* Admin: separate route prefix + guard, **TOTP MFA mandatory**, IP/device session list, forced password rotation policy optional.
* RBAC: roles → permissions named `<resource>.<action>` (`orders.refund`); Laravel Policies for ownership. Candidate package `spatie/laravel-permission` 🔎 (evaluate, record ADR).
* Guest checkout creates a `customers` record keyed by phone/email with no password; can be claimed later via OTP.

## 12. API conventions (🔒 unless noted)

* `/api/v1/*`, JSON only, resources (never raw models). Success: `{ "data": …, "meta": …, "links": … }`.
* Errors: RFC 9457 `application/problem+json` with stable machine `code` (`stock.insufficient`, `payment.verification_failed`, `validation.failed` + field errors). Messages localised per `Accept-Language`.
* Pagination: cursor for feeds/orders; page-based for catalog listings. Filtering `filter[...]`, sorting `sort=-created_at`.
* `Idempotency-Key` header on critical POSTs. Rate limits per route group (auth strict, catalog lenient).
* OpenAPI 3.1 generated from code and checked in CI (diff = review). 🔎 evaluate Scramble vs L5-Swagger.
* Deprecation: `Deprecation`/`Sunset` headers, minimum 6-month overlap for a retired version (proposed).

## 13. Search (🔒 interface · 💡 engine)

* `CatalogSearch` contract. MVP engine = **PostgreSQL**: `tsvector` (config `simple` + `unaccent`) on translation tables + `pg_trgm` for typo tolerance/autocomplete. Both locales indexed.
* Bangla note: PostgreSQL ships no Bangla stemmer — rely on tokenisation + trigram; add a **normalisation pipeline** (NFC, ZWJ/ZWNJ handling, digit mapping, common spelling variants). Banglish transliteration search is a post-MVP candidate.
* Swap to Meilisearch/Typesense only after measured need (see performance targets).

## 14. Measurable targets (💡 proposed — confirm with business)

| Area | Target |
|---|---|
| Storefront LCP (mobile, simulated Fast 3G/Moto G class) | ≤ 2.5 s p75 |
| Catalog/PDP server time (cached / uncached) | p95 ≤ 200 ms / ≤ 600 ms |
| Checkout & payment-init API | p95 ≤ 800 ms |
| Search autocomplete | p95 ≤ 300 ms |
| Page weight (home, mobile, first load) | ≤ 1.0 MB transferred |
| Availability (monthly) | ≥ 99.5 % (stretch 99.9 %) |
| RPO / RTO | ≤ 15 min / ≤ 4 h |
| Oversell incidents | 0 (proven by concurrency tests) |
| Duplicate payment application | 0 (proven by replay tests) |

## 15. MVP classification

| Capability | Class |
|---|---|
| Physical + digital + service catalog, variants, bilingual content | **MVP** |
| Cart, guest + account checkout, COD, one online gateway (+ one MFS if feasible) | **MVP** |
| One courier integration + manual shipping fallback | **MVP** |
| Coupons (basic), order tracking, email + SMS notifications | **MVP** |
| Admin: catalog, orders, payments, inventory, shipments, CMS, RBAC, audit log | **MVP** |
| Reviews, wishlist, returns/refund requests | **MVP-late / Phase 2** |
| Mobile API v1 (read + checkout) | **Phase 2** (designed in MVP, built after web) |
| Additional gateways/couriers, advanced search, loyalty points, push notifications | **Phase 2** |
| Subscriptions, gift cards, B2B pricing, pre-orders | **Future** |
| Multi-vendor marketplace, commissions, auctions | **Future — but keep `vendor_id` out of MVP tables only if an ADR shows retrofitting is cheap; otherwise reserve nullable `vendor_id` on products/orders** |

## 16. Bangladesh compliance — candidates to VERIFY (not legal advice)

The AI must research the *current* status/text of each item, cite the official source with access date, and mark it **Verified / Assumed / Needs lawyer**. Never claim the platform "is compliant".

* Digital Commerce Operation Guidelines (Ministry of Commerce) — seller disclosures, delivery/return obligations, payment-holding rules.
* Consumer Rights Protection Act 2009 — refunds, misleading advertising, complaints.
* VAT & Supplementary Duty Act 2012 (NBR) — VAT rate, registration (BIN), **VAT invoice format (Mushak 6.3 / current equivalent)**, e-commerce VAT rules.
* Bangladesh Bank rules on payment service providers/MFS; payment-gateway merchant onboarding requirements (trade licence, TIN, bank account, etc.).
* Current cyber-security / ICT legislation and any enacted personal-data-protection law or ordinance (status has changed in recent years — verify what is in force today).
* BTRC rules for SMS sender-ID / bulk SMS.
* Trade licence, TIN, BIN, and any marketplace/e-CAB registration the business needs.

## 17. Provisional assumptions (replace once you answer the open questions)

1. Single merchant (no marketplace) at launch; one legal entity; BDT only.
2. Team: 1 senior developer + AI agents + part-time QA/designer.
3. Hosting: Linux VPS/cloud with managed or self-managed PostgreSQL + Redis; region chosen for latency to Bangladesh (data-residency obligations 🔎).
4. First gateway = one aggregator (e.g., SSLCommerz / ShurjoPay / aamarPay) 🔎 + COD; bKash/Nagad direct as Phase 2 unless the aggregator covers them.
5. First courier = one of Pathao/Steadfast 🔎, plus manual dispatch.
6. Default storefront language = Bangla *or* English — **business decision pending** (architecture supports both equally).
