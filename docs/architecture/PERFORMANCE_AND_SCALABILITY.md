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
