# Phase 10 — Administration, Reporting & CMS

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | 18–25 person-days |
| **Prerequisites** | Phases 5–9 (data to report on) |
| **Modules** | Reporting, Cms, Platform Admin, Integrations (settings), Notifications (templates admin) |
| **Attach** | `REPORTING_AND_ANALYTICS.md`, `WEB_UI_UX_GUIDELINES.md` (admin), `SECURITY_AND_PRIVACY.md` |
| **Exit gate** | Operations, finance and content teams can run the business from the admin without developer help; reports reconcile to the ledger |

## Copy-paste prompt

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

OBJECTIVE
Complete the back-office: dashboards, trustworthy reports, content management, settings and operational tooling.

TASKS
1. **Dashboards (role-aware):** today's orders/revenue, pending actions (to confirm, to ship, failed payments, unremitted COD, low stock, pending reviews, open tickets), trends. Each tile links to a pre-filtered list. Metric definitions come from REPORTING_AND_ANALYTICS.md — one definition per metric, in code (`MetricDefinition` classes), reused by dashboard, export and API.
2. **Reports (all filterable by date range, channel, payment method, category, courier, locale):** sales summary; sales by product/category/brand; gross vs net (gross sales, discounts, delivery charges, taxes, collected, refunded, net, recognised revenue — exact definitions per doc); payment-method analysis; COD collection/remittance aging; pending/failed payments; refunds & cancellations (reasons); delivery performance (SLA, failed rate by zone/courier); inventory valuation/aging/low-stock/stock-out; best sellers; customer cohorts & repeat rate; coupon/campaign performance; service bookings utilisation/no-show; search zero-results.
3. **Reporting data layer:** read-optimised SQL views / materialised views / summary tables refreshed by scheduled jobs (decide per measured need); reports never lock OLTP tables; large exports are async jobs delivered via signed, expiring links; permission-gated (`reports.finance.view`, `customers.export`); export audit.
4. **CMS:** pages, banners, home-page sections (drag-order), menus, footer, FAQ, policy pages (terms, privacy, returns, shipping, COD) with **versioning + effective date + required-acceptance tracking**, media library, SEO fields, preview before publish, scheduled publish, bn/en tabs with missing-translation dashboard.
5. **Settings & integrations:** business profile (name, address, BIN/TIN display, invoice footer), tax settings, currency/locale settings, payment/courier/SMS/email provider credentials (encrypted, masked, test-connection button, change audited), feature flags, maintenance mode, notification templates.
6. **Operations tooling:** audit log viewer (filter/search/export), queue/failed-job monitor (Horizon + custom failed-job UI with safe retry), scheduled-task status, system health page, data-integrity checks dashboard (stock ledger, orphaned payments, order-total mismatches).
7. **Bulk & import/export:** products, prices, stock, customers (permissioned), orders (permissioned); dry-run, error report, rollback for imports where feasible.
8. **Staff productivity:** global admin search (order no., phone, SKU, customer), saved filters, keyboard shortcuts, bulk actions with confirmation + audit.

SECURITY: field-level protection for PII in lists (mask phone/email unless `customers.view_pii`); export rate limits; no raw SQL report builder exposed to users; settings secrets never returned to the browser.

TESTS (must pass)
- **Reconciliation tests:** seeded scenarios (partial refunds, coupons, COD, cancellations, partial shipments) → report totals equal ledger sums and match hand-calculated expectations.
- Permission matrix on every report/export route; masked PII for roles without permission.
- Async export lifecycle (queued → ready → expired link → denied after expiry).
- CMS versioning/effective-date logic; policy acceptance recorded.
- Query-plan checks: each report < agreed time on 1M-order synthetic dataset (see performance doc).
- Settings change audit includes who/when/old/new (secrets redacted).

ACCEPTANCE CRITERIA
- Finance can close a day: collected vs refunded vs COD outstanding ties to gateway/courier statements via the reconciliation screens.
- Every metric shown anywhere has exactly one definition.

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
