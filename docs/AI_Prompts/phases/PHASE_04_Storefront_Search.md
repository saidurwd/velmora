# Phase 4 — Storefront & Search

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | 12–18 person-days |
| **Prerequisites** | Phase 3 |
| **Modules** | Storefront (Catalog read side), Cms (home/banners/static pages), Search |
| **Attach** | `WEB_UI_UX_GUIDELINES.md`, `PERFORMANCE_AND_SCALABILITY.md`, `BANGLADESH_LOCALIZATION_AND_COMMERCE.md` |
| **Exit gate** | Fast, accessible, bilingual browsing and search that meets the performance budgets on a throttled mobile profile |

## Copy-paste prompt

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

OBJECTIVE
A professional, mobile-first, low-bandwidth storefront for browsing and finding products, services and digital goods.

TASKS
1. **Design system in Blade:** tokens (colour, type scale for Latin + Bangla, spacing, radius, elevation), components (button, input, select, badge, price, rating, product card, service card, breadcrumb, pagination, modal/drawer, toast, skeleton, empty state, alert). Documented in a living `/dev/components` page (non-production). WCAG 2.2 AA contrast; focus states; skip links; form error summaries.
2. **Pages:** home (hero banners, featured collections, categories, new/best-selling — data-driven via CMS), category listing, brand page, collection page, product detail (physical/digital/service variants of the template), static/CMS pages, FAQ, 404/500 pages (en/bn), maintenance page.
3. **Listing UX:** faceted filters (category, brand, price range, attributes, availability, product type), sorting (relevance, newest, price asc/desc, popularity), pagination (cursor or page per ADR), URL-stateful filters (shareable), Alpine-enhanced but works without JS.
4. **Search:** `CatalogSearch` contract + PostgreSQL implementation per BD §13: normalisation pipeline, `tsvector` columns (generated or maintained by trigger/job), `pg_trgm` for fuzzy + autocomplete, ranking (exact SKU/name > prefix > fuzzy), synonyms table (admin-managed, bn↔en e.g. "মোবাইল"/"mobile"), zero-result tracking (`search_queries` table, PII-free) for merchandising, debounce + keyboard-accessible autocomplete.
5. **SEO:** per-locale meta, canonical + hreflang, Open Graph, JSON-LD `Product`/`BreadcrumbList`/`Organization`, XML sitemaps per locale (queued generation), robots.txt, clean slugs, 301 on slug change (redirect table).
6. **Performance:** HTTP caching headers, fragment/page caching for anonymous pages with safe invalidation on catalog events, image `srcset`/`sizes`, lazy loading, critical CSS, font preloading, Vite code-splitting, no layout shift, Core Web Vitals budget enforced in CI (Lighthouse CI against seeded data).
7. **Recently viewed & related:** cookie/local-first recently viewed; related products by category/attribute rules (simple, not ML).
8. **Analytics hooks:** privacy-respecting event layer (page view, search, view_item) behind a consent flag; no third-party scripts unless approved.

DATABASE: search columns/indexes, `search_synonyms`, `search_queries`, `slug_redirects`, `cms_pages(+translations)`, `banners(+translations)`.
SECURITY: escape all output; sanitise search input; rate-limit search/autocomplete; safe redirects; CSP tightened for storefront.

TESTS (must pass)
- Search relevance fixtures (en, bn, mixed, typos, SKU, synonyms, Bangla digits, zero-width characters) with expected ordering.
- Filter/sort combinations produce correct, stable, paginated results; query-count and EXPLAIN-based index-usage checks on 10k+ seeded products.
- hreflang/canonical correctness; sitemap content; JSON-LD validity; slug redirect.
- Accessibility automation (axe) on key templates in both locales; keyboard-only path test for autocomplete.
- Lighthouse CI budgets (see 02 §14) on home, listing, PDP using throttled mobile profile.
- Cache invalidation: price/stock change shows on PDP within the documented staleness window.

ACCEPTANCE CRITERIA
- Mobile LCP ≤ 2.5 s (p75, throttled) on home/listing/PDP with seeded data; home first-load transfer ≤ 1.0 MB.
- Bangla and English content render with correct fonts everywhere (visual regression snapshots in both locales).
- Works acceptably with JavaScript disabled for browse/search/filter.

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
