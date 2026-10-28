# Performance and Scalability (Batch 6 draft)

Targets are **proposals to confirm** (baseline §14). No extra infrastructure (Kubernetes, Kafka, Elasticsearch, microservices) without measured need.

## 1. Targets
| Area | Target |
|---|---|
| Storefront LCP (mobile, throttled) | ≤ 2.5 s p75 |
| CLS / INP | ≤ 0.1 / ≤ 200 ms |
| Home first-load transfer | ≤ 1.0 MB |
| Catalog/PDP server time cached / uncached | p95 ≤ 200 / ≤ 600 ms |
| Search autocomplete | p95 ≤ 300 ms |
| Checkout & payment-init | p95 ≤ 800 ms |
| Admin list pages | p95 ≤ 1.0 s on 1 M orders |
| Reports (interactive) | ≤ 5 s on 1 M orders; larger → async export |
| Availability | ≥ 99.5 % monthly; RPO ≤ 15 min; RTO ≤ 4 h |
| Oversell / double-paid | 0 |
| Sizing assumption [ASSUMPTION — confirm] | launch ≈ 500 orders/day, peak campaign 10× for 1 h; 100 concurrent shoppers; 50 k products max in year 1 |

## 2. Likely bottlenecks and mitigations
| Bottleneck | Mitigation |
|---|---|
| Catalog listing with facets | covering indexes, denormalised `product_search` read table refreshed on events, cached facet counts |
| Bangla/English search | FULLTEXT + prefix indexes; engine swap behind `CatalogSearch` on trigger |
| Stock hot rows (campaign SKU) | conditional UPDATE is O(1) row lock; shard by location; short transactions; retry/jitter; queue-based order intake as fallback (ADR if measured) |
| Checkout transaction length | no external calls inside; pre-compute quotes |
| Gap-lock deadlocks | READ COMMITTED, ordered locks, bounded retry; deadlock-retry metric |
| Reports on OLTP | summary tables / read replica later; no report locks OLTP tables |
| Images | WebP/AVIF responsive sizes, CDN, lazy loading |
| Queue backlog | Horizon with separate queues (critical: payments/outbox; default; low: notifications/exports) and alerts on lag |
| N+1 | `preventLazyLoading` in non-prod, query-count assertions |

## 3. Caching
Per SYSTEM_ARCHITECTURE §9. Staleness windows documented: price/stock on PDP ≤ 60 s (cart/checkout always live DB).

## 4. Scaling path
1. Single app server + managed MariaDB + Redis. 2. Horizontal app/worker scale-out (stateless; sessions/cache in Redis; media in object storage). 3. Read replica for reports/search reads. 4. Dedicated search engine (trigger-based). 5. Partition `audit_events`/`stock_movements`/`orders` archives beyond ~50 M rows. Connection management: persistent pooling via ProxySQL/MaxScale only if needed.

## 5. Reliability
Queue retry with backoff + dead-letter view; graceful degradation table (SYSTEM_ARCHITECTURE §10); scheduler heartbeat alert; maintenance mode; migrations expand/contract.

## 6. Performance test plan
k6 scenarios: browse, search, add-to-cart, checkout (COD + prepaid sandbox), webhook burst, admin reports, last-unit contention. Lighthouse CI in PR on seeded data (throttled mobile). Dataset: 50 k products, 1 M orders, 5 M stock movements synthetic. Tools: slow query log, `performance_schema`, EXPLAIN review per new query (Phase gate), APM. Pass/fail against §1; waivers need written rationale.


## Storefront caching and budgets (implemented in Phase 4)

**What is cached.** Anonymous storefront reads — home page data, listing pages (per filter/sort/page), filter choices, footer page links — go through `StorefrontCache`: Redis/database cache entries keyed by a *storefront version* and the language. TTL ≤ 120 s as a backstop.
**Invalidation.** Anything that changes what the public sees bumps the version, so the next request rebuilds: catalog edits (`ProductListingChanged`), taxonomy and attribute saves, CMS pages and banners, and the once-a-minute `ReconcileListing` job whenever it finds a price (including sales starting/ending) or stock change.
**Documented staleness window.** Listing and home prices/availability: **≤ ~60 s** (the reconcile cadence). Catalog and CMS edits: immediate. Product pages, cart and checkout never use this cache: they read live price and stock. A banner's own start/end time is honoured within the 120 s TTL.
**HTTP.** Public pages send `Cache-Control: private, max-age=0, must-revalidate` with an ETag; unchanged pages answer `304` with no body. Fingerprinted build assets (`/build/assets/*`) must be served `Cache-Control: public, max-age=31536000, immutable` by the web server (see DEPLOYMENT_AND_DEVOPS).
**Fonts.** The Latin variable font is preloaded on every page and the Bangla regular weight on `/bn`; fonts use `font-display: swap`.
**Critical CSS.** Not extracted: the whole stylesheet is ≈ 9 kB gzipped, so inlining would not beat one cached request. Revisit if it passes ≈ 30 kB gzipped.
**Budgets.** `lighthouserc.json` + `.github/workflows/lighthouse.yml` run Lighthouse (mobile profile, simulated throttling, 3 runs) on home (en, bn), shop, a category and a product page against seeded demo data. Budgets: LCP ≤ 2.5 s, CLS ≤ 0.1, TBT ≤ 300 ms, page weight ≤ 1 MB, performance ≥ 85, accessibility ≥ 95, SEO ≥ 95. **Advisory** (warnings, non-blocking) until a few baseline runs are green; then switch to `error` and drop `continue-on-error`.
**Scale check.** `RUN_SCALE_TESTS=1 vendor/bin/pest tests/Database/ScaleTest.php` seeds 12,000 bilingual products and checks every listing/search query plan (no full scan of a table over 500 rows) and timings. Measured on a developer laptop (MariaDB 12.1, busy machine): listing pages 3–45 ms, search 11–110 ms, cold home page 339 ms.
