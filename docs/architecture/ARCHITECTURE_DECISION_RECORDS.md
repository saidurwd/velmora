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
