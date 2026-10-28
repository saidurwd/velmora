# Phase 3 — Catalog, Product Information & Inventory Core

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | 15–22 person-days |
| **Prerequisites** | Phase 2 |
| **Modules** | Catalog, Pricing (base/sale), Inventory (core), Cms (media), Platform |
| **Attach** | `DATABASE_DESIGN.md` (catalog + inventory sections), `WEB_UI_UX_GUIDELINES.md` (admin editor) |
| **Exit gate** | Staff can create bilingual physical/digital/service products with variants, media, prices and stock; concurrency tests for stock pass |

> **Change vs Draft.md:** the *inventory core* (locations, levels, ledger, reservations, availability) moves here from Phase 7, because Cart/Checkout (Phase 5) cannot be correct without atomic reservation. Phase 7 keeps warehouses-ops, couriers, returns receive-back.

## Copy-paste prompt

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

OBJECTIVE
A complete, bilingual product information model and admin tooling, plus a concurrency-safe inventory core.

TASKS
1. **Taxonomy:** categories (hierarchy: ltree or closure table per ADR; translations; ordering; SEO), brands, tags, collections (manual + rule-based later), `tax_classes`.
2. **Products:** `products` + `product_translations` (name, slug, short/long description, SEO) + `product_type` (physical|digital|service) + status workflow (draft → published → archived) + scheduled publish.
3. **Variants:** options/attributes/attribute_values (+translations), variant generation from option matrix, `product_variants` (SKU unique, barcode, dimensions, weight, track_inventory), default variant for simple products. Specification attributes (filterable vs display-only).
4. **Pricing (base):** `prices` with currency, regular, compare-at, scheduled sale window, tax-inclusive flag; price resolver Action returning a `PriceQuote` DTO (used later by Cart/Checkout/API). Bulk price update with preview + audit.
5. **Media:** upload pipeline (type/size allow-list, virus-scan hook, re-encode, strip EXIF, generate responsive WebP/AVIF sizes), alt text per locale, ordering, per-variant images, S3-compatible disk.
6. **Inventory core:** `stock_locations`, `stock_levels`, `stock_movements`, `stock_reservations` exactly per BD §6; Actions `ReserveStock`, `ReleaseReservation`, `CommitReservation`, `AdjustStock` (reason codes), `ExpireReservations` job; availability query service (`AvailableQuantity` per variant, aggregated across locations). Low-stock threshold + alert notification hook. Nightly `VerifyStockLedger` job (on_hand == sum(movements)).
7. **Admin UI (Blade):** product list (filter by type/status/category/brand/stock, search, bulk actions), product editor with **en/bn tabs** and missing-translation indicators, variant matrix editor, media manager, price editor, stock adjustment screen, category/brand/attribute CRUD, import/export CSV (template with bn+en columns; dry-run validation report; async job; row-level errors).
8. **Type-specific stubs:** admin form sections for digital and service products are present but only store the *definition* fields; fulfilment arrives in Phase 8.
9. **Seeders:** realistic demo catalog (≥ 40 products incl. Bangla names, 5 categories, variants, one digital, one service).

DATABASE: all catalog tables + translations + inventory tables. Indexes: `UNIQUE(sku)`, `UNIQUE(locale, slug)` on translations, partial index on published products, GIN/trigram on translated names (prepare, finalised in Phase 4), FK indexes. CHECK constraints per BD-25.
SECURITY: authorize every admin action (`catalog.*`, `inventory.*`); upload hardening; HTML sanitisation of descriptions (allow-list, e.g. HTMLPurifier or equivalent) to prevent stored XSS; audit all price/stock changes.

TESTS (must pass)
- **Concurrency:** N parallel processes reserve the last 1 unit → exactly 1 succeeds, stock never negative (run against real PostgreSQL with parallel workers/processes).
- Multi-line reservation in opposite order from two processes → no deadlock failure visible to caller (retry logic proven).
- Reservation expiry returns stock; double-release is a no-op; commit after expiry is rejected.
- Ledger invariant test after random operation sequences (property-style).
- Price resolver: scheduled sale boundaries (Asia/Dhaka vs UTC), tax-inclusive/exclusive, rounding with Money allocation.
- Translation fallback & uniqueness of slug per locale; XSS payload in description is neutralised.
- CSV import: bad rows reported, good rows committed atomically per chunk, re-import idempotent by SKU.
- Query-count assertions on admin list pages (no N+1).

ACCEPTANCE CRITERIA
- Staff can publish a product with bn+en content, 3 variants, images, sale price and stock in under 5 minutes (usability check).
- No code path can drive `on_hand` or `reserved` negative; DB CHECK is the last line of defence and has its own test.
- Everything created here is readable through query services (not raw models) for later modules.

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
