# Performance report — Phase 12 (first pass, development laptop)

**Verdict: no performance problem was found in the code, but the targets are NOT proven.** The numbers below come from a busy developer laptop with the database in a Docker VM, not from production-like infrastructure. They are a smoke test and a way to find bugs (it found one, below), not a capacity statement. The k6 scenario is saved (`tests/performance/storefront-api.k6.js`) so the real test can be run on staging by someone who has it.

## Setup (and why it limits what the numbers mean)

* PHP 8.4 built-in server with 6 workers and opcache on (`APP_ENV=e2e`, `APP_DEBUG=false`), MariaDB 11.4 in Docker, file cache and sessions, demo data (about 50 products). One machine ran the app, the database, the load generator **and** a lot of other software (load average 8 to 100 during the session). No CDN, no Redis, no php-fpm, no network.
* Scenario: a mix of home / shop / category pages, a product page, search suggestions, catalog API reads, and a guest adding to the cart and asking for a checkout quote. Each virtual user presented a different visitor address.

## Results

| Virtual users | Throughput | Failed | Home/shop/category p95 | Product page p95 | Suggest p95 | API list p95 | API product p95 | Cart add p95 | Checkout quote p95 |
|---|---|---|---|---|---|---|---|---|---|
| 5 (about 19 requests/s) | 19/s | 0 % | 370 ms | 479 ms | 282 ms | 230 ms | 370 ms | 399 ms | 501 ms |
| 20 (saturated) | 27/s | 0 % | 946 ms | 1,224 ms | 868 ms | 738 ms | 1,005 ms | 1,108 ms | 1,297 ms |

Against the targets (`PERFORMANCE_AND_SCALABILITY.md` §1): at light load every measured p95 is inside its target (product page ≤ 600 ms uncached, suggestions ≤ 300 ms, checkout quote ≤ 800 ms; the cached-page target of ≤ 200 ms was **not** met on this laptop at 5 users, but that is dominated by the machine). At 20 users the laptop is saturated at about 27 requests/s and everything is slow; that shows this setup's ceiling, not the application's.

## In-process cost (warm, one request, no network)

| Request | Time | Queries | Time in SQL |
|---|---|---|---|
| Home, shop, API product list | 8 to 20 ms | 0 (served from the listing cache) | 0 |
| Category page | 37 ms | 8 | 16 ms |
| Search suggestions | 20 ms | 7 | 13 ms |
| API product | 53 ms | 18 | 37 ms |
| **Product page** | 93 ms | 35 | 66 ms |

The product page is the heaviest. Its 35 queries are bounded (same for every product) and none is an N+1 loop; four of them look the same product up by its public id from different parts of the page and two look up the category twice. That could be trimmed by remembering the lookup, but it is worth a few milliseconds, not a change now.

## Found by the load test

* **`TRUSTED_PROXIES` in `.env` was ignored, and `*` did not mean "trust all"** (Security register F-12). Behind a load balancer every visitor would have looked like the balancer and shared one rate-limit bucket: 120 public reads a minute and 10 sign-ins a minute for the **whole shop**. Fixed with tests.

## Not done (needs production-like infrastructure or people)

1. The real test: staging with php-fpm, opcache, Redis, the production database size, a CDN in front, and k6 running from another machine. Targets to check: catalog/product p95 ≤ 200 ms cached and ≤ 600 ms uncached, suggestions ≤ 300 ms, checkout and payment start ≤ 800 ms, admin lists ≤ 1 s on a million orders.
2. Webhook burst (a storm of gateway notifications), payment initiation under load, admin report load, and the checkout *placing* orders concurrently under load (the correctness of concurrent placement is proved separately by the concurrency tests; throughput is not measured).
3. Slow-query review on a production-sized data set: Phase 10's scale test covers the report queries at 550,000 parcels; the storefront and order queries were not re-run at a million orders here.
4. Real-user figures (Lighthouse in CI covers lab measurements on the demo data).
