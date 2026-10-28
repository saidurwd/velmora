# Task Backlog (Batch 7)

Epics = phases; IDs `E<phase>-<n>`; estimate in person-days (AI-assisted); dependency = previous item in the epic unless noted. **Acceptance criteria for every item = the corresponding bullet/test in `docs/AI_Prompts/phases/PHASE_nn_*.md` plus the Definition of Done**; high-risk (money/stock/auth/schema) items require plan approval. Cross-epic dependency: epic N depends on epics listed in the roadmap.


## Epic E1 — Phase 1: Foundation

| ID | Story / task | Module | Est (d) | Risk | Depends |
|---|---|---|---|---|---|
| E1-1 | Docker Compose (Redis, S3/SeaweedFS, Mailpit; app on host) + .env.example — DONE | Platform | 2 | normal | — |
| E1-2 | Module skeleton generator + arch tests (BD-11) | Platform | 3 | normal | E1-1 |
| E1-3 | Platform kernel: Money, Clock, RequestContext, Settings, Audit, Idempotency, Outbox, PublicId, PhoneNumber, BanglaText | Platform | 6 | normal | E1-2 |
| E1-4 | Error handling (web bn/en pages, problem+json), JSON logging + redaction | Platform | 2 | normal | E1-3 |
| E1-5 | CI: Pint, Larastan, Pest, parity tests, audit, secret scan | Platform | 3 | normal | E1-4 |
| E1-6 | Base layouts, Tailwind tokens, self-hosted Bangla font, health endpoints, Horizon | Platform | 3 | normal | E1-5 |
| E1-7 | DB conventions: connection tz +00:00, READ COMMITTED helper, append-only trigger helper, deadlock retry | Platform | 2 | normal | E1-6 |

## Epic E2 — Phase 2: Identity & localisation

| ID | Story / task | Module | Est (d) | Risk | Depends |
|---|---|---|---|---|---|
| E2-1 | Users/customers, email+password auth | IdentityAccess | 2 | HIGH | E1 |
| E2-2 | OTP service + SmsGateway contract + SSL Wireless adapter + pumping guards | IdentityAccess | 3 | HIGH | E2-1 |
| E2-3 | Staff guard, TOTP MFA, recovery codes | IdentityAccess | 2 | HIGH | E2-2 |
| E2-4 | RBAC roles/permissions + admin UI + last-Super-Admin rule | IdentityAccess | 3 | HIGH | E2-3 |
| E2-5 | Locale middleware, switcher, hreflang, formatters (lakh/crore bn) | Platform | 3 | normal | E2-4 |
| E2-6 | Route-permission deny-by-default test | IdentityAccess | 1 | normal | E2-5 |

## Epic E3 — Phase 3: Catalog & inventory

| ID | Story / task | Module | Est (d) | Risk | Depends |
|---|---|---|---|---|---|
| E3-1 | Taxonomy (closure tables), brands, tags, collections | Catalog | 3 | HIGH | E2 |
| E3-2 | Products, translations, variants, attributes | Catalog | 5 | normal | E3-1 |
| E3-3 | Prices + resolver + bulk update | Pricing | 3 | normal | E3-2 |
| E3-4 | Tax classes/rates tables + settings (engine in P5) | Pricing | 1 | HIGH | E3-3 |
| E3-5 | Media pipeline (re-encode, WebP/AVIF, alt per locale) | Catalog | 3 | normal | E3-4 |
| E3-6 | Inventory core: levels, ledger, reservations, ReserveStock/Release/Commit/Adjust, expiry job, ledger check | Inventory | 5 | HIGH | E3-5 |
| E3-7 | Admin catalog UI en/bn tabs, CSV import/export | Catalog | 5 | normal | E3-6 |
| E3-8 | Concurrency tests (last unit, ordered locks, expiry) | Inventory | 2 | HIGH | E3-7 |

## Epic E4 — Phase 4: Storefront & search

| ID | Story / task | Module | Est (d) | Risk | Depends |
|---|---|---|---|---|---|
| E4-1 | Blade component library + /dev/components | Platform | 4 | normal | E3 |
| E4-2 | Home, listing, PDP (3 types), CMS pages | Catalog | 5 | normal | E4-1 |
| E4-3 | Search: normaliser, FULLTEXT, synonyms, autocomplete, zero-result log | Search | 4 | normal | E4-2 |
| E4-4 | SEO: meta, hreflang, JSON-LD, sitemaps, redirects | Cms | 2 | normal | E4-3 |
| E4-5 | Caching + invalidation, Lighthouse CI budgets, axe tests | Platform | 3 | normal | E4-4 |

## Epic E5 — Phase 5: Cart, checkout, orders, tax

| ID | Story / task | Module | Est (d) | Risk | Depends |
|---|---|---|---|---|---|
| E5-1 | Geography seed + addresses | Customers | 3 | normal | E4 |
| E5-2 | Cart (guest token, merge, expiry) | Cart | 3 | normal | E5-1 |
| E5-3 | Tax engine (inclusive/exclusive, shipping/COD taxability, allocation) | Pricing | 4 | HIGH | E5-2 |
| E5-4 | Coupons/promotions (atomic redemption) | Pricing | 3 | normal | E5-3 |
| E5-5 | Shipping zones/rates/quotes/COD rules | Shipping | 3 | normal | E5-4 |
| E5-6 | PlaceOrder Action (one txn, idempotent, outbox) | Checkout | 4 | HIGH | E5-5 |
| E5-7 | Orders core + 3 state machines + history | Orders | 4 | HIGH | E5-6 |
| E5-8 | COD fraud rules + needs_review | Orders | 2 | normal | E5-7 |

## Epic E6 — Phase 6: Payments

| ID | Story / task | Module | Est (d) | Risk | Depends |
|---|---|---|---|---|---|
| E6-1 | Payments domain tables + PaymentGateway contract + registry + FakeGateway | Payments | 4 | HIGH | E5 |
| E6-2 | SSLCommerz adapter (initiate, validate, query, refund) from saved docs | Payments | 5 | HIGH | E6-1 |
| E6-3 | Callback pipeline + replay/forgery suite | Payments | 6 | HIGH | E6-2 |
| E6-4 | Expiry + late-success handling + reconciliation job | Payments | 4 | normal | E6-3 |
| E6-5 | Refunds (guard, approval, manual path) | Payments | 4 | HIGH | E6-4 |
| E6-6 | COD collections/remittances | Payments | 3 | normal | E6-5 |
| E6-7 | Admin payments UI, alerts/metrics | Payments | 4 | HIGH | E6-6 |

## Epic E7 — Phase 7: Fulfilment & couriers

| ID | Story / task | Module | Est (d) | Risk | Depends |
|---|---|---|---|---|---|
| E7-1 | Shipments, items, events, status map | Shipping | 4 | normal | E6 |
| E7-2 | CourierProvider contract + ManualCourier | Shipping | 2 | normal | E7-1 |
| E7-3 | First real courier adapter (from saved docs) | Shipping | 5 | normal | E7-2 |
| E7-4 | Webhook + polling sync, out-of-order handling | Shipping | 3 | HIGH | E7-3 |
| E7-5 | Returns/RMA + stock receive-back | Shipping | 4 | HIGH | E7-4 |
| E7-6 | Invoices/credit notes + PDFs (bn font) | Orders | 4 | normal | E7-5 |
| E7-7 | Courier reconciliation + stock transfers/counts | Shipping | 4 | HIGH | E7-6 |

## Epic E8 — Phase 8: Digital & services

| ID | Story / task | Module | Est (d) | Risk | Depends |
|---|---|---|---|---|---|
| E8-1 | Digital assets, entitlements, signed downloads, logs | DigitalProducts | 5 | HIGH | E7 |
| E8-2 | License key pool | DigitalProducts | 2 | normal | E8-1 |
| E8-3 | Services, packages, resources, availability | Services | 4 | normal | E8-2 |
| E8-4 | Bookings with WITHOUT OVERLAPS, holds, slots search | Services | 5 | HIGH | E8-3 |
| E8-5 | Policies: cancellation/refund, reminders | Services | 3 | HIGH | E8-4 |

## Epic E9 — Phase 9: Engagement

| ID | Story / task | Module | Est (d) | Risk | Depends |
|---|---|---|---|---|---|
| E9-1 | Account area + privacy tools | Customers | 4 | normal | E8 |
| E9-2 | Notification templates/logs/preferences | Notifications | 5 | normal | E9-1 |
| E9-3 | Reviews + stats | Engagement | 3 | normal | E9-2 |
| E9-4 | Wishlist, support tickets | Engagement | 4 | normal | E9-3 |

## Epic E10 — Phase 10: Admin, reports, CMS

| ID | Story / task | Module | Est (d) | Risk | Depends |
|---|---|---|---|---|---|
| E10-1 | MetricDefinition classes + summary tables | Reporting | 6 | normal | E9 |
| E10-2 | Report catalogue + async exports | Reporting | 6 | normal | E10-1 |
| E10-3 | CMS versions/policies acceptance | Cms | 4 | normal | E10-2 |
| E10-4 | Settings/integrations/feature flags, audit viewer, health | Platform | 5 | normal | E10-3 |
| E10-5 | Dashboards + global search | Reporting | 4 | normal | E10-4 |

## Epic E11 — Phase 11: Mobile API

| ID | Story / task | Module | Est (d) | Risk | Depends |
|---|---|---|---|---|---|
| E11-1 | API foundation (envelope, errors, idempotency, tiers) | Platform | 3 | normal | E10 |
| E11-2 | Auth: tokens, refresh rotation, devices | IdentityAccess | 4 | HIGH | E11-1 |
| E11-3 | Endpoints per catalog | All | 8 | normal | E11-2 |
| E11-4 | OpenAPI + CI gate + parity/IDOR tests + integration guide | Platform | 4 | normal | E11-3 |

## Epic E12 — Phase 12: Hardening

| ID | Story / task | Module | Est (d) | Risk | Depends |
|---|---|---|---|---|---|
| E12-1 | E2E Playwright en/bn | Platform | 4 | normal | E11 |
| E12-2 | Security review + pen-test coordination | Platform | 4 | normal | E12-1 |
| E12-3 | Load tests + tuning | Platform | 3 | normal | E12-2 |
| E12-4 | Restore rehearsal + runbooks | Platform | 3 | normal | E12-3 |
| E12-5 | Go/no-go report | Platform | 1 | normal | E12-4 |

## Epic E13 — Phase 13: Launch

| ID | Story / task | Module | Est (d) | Risk | Depends |
|---|---|---|---|---|---|
| E13-1 | IaC + deploy/rollback pipeline | Platform | 3 | normal | E12 |
| E13-2 | Monitoring/alerts/backups verification | Platform | 2 | normal | E13-1 |
| E13-3 | Cutover plan + hypercare | Platform | 2 | normal | E13-2 |

## Deferred from Phase 3 (found while building; not yet scheduled)
| Item | Module | Note |
|---|---|---|
| Stock filter and bulk actions on the product list | Catalog / Inventory | needs an Inventory contract for "in stock / out of stock" product sets |
| Low-stock list and stock-count worksheet | Inventory | `StockLow` event already fires |
| Inline edit of an existing variant (SKU, status, options, dimensions) | Catalog | add / generate / delete exist |
| Variant-level images in the media manager | Catalog | `product_media.variant_id` column exists |
| CSV import for option products / variants, and categories | Catalog | importer is simple-product only by design |
| Translate business-rule messages shown in import errors | Catalog | currently English exception text |
| Delete stale dry-run uploads from the private disk | Catalog | scheduled cleanup job |
| Product type-specific fields (digital file, service duration) | Catalog | arrive with Phases 8 and 9 |

## Parking lot (not in MVP)
Direct bKash adapter · Nagad · extra couriers · Meilisearch/Typesense (trigger-based) · purchasing/suppliers (Q9) · loyalty · push campaigns · subscriptions · gift cards · B2B price lists · pre-orders · marketplace/vendors (ADR-028).
