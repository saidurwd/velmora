# Reporting and Analytics (Batch 6 draft)

**Rule:** one definition per metric, implemented once as a `MetricDefinition` class and reused by dashboard, export and API. Reports read summary tables/views refreshed by scheduled jobs; they never lock OLTP tables. Amounts are minor units in BDT; tax per tax settings (zero while `tax.enabled=false`).

## 1. Metric definitions (precise)
| Metric | Definition |
|---|---|
| Gross sales | Σ `order_items.line_total_minor` (as priced, before order-level discounts) for orders with `status` not `cancelled` and `placed_at` in period. Includes tax if prices are tax-inclusive |
| Discounts | Σ line discounts + order-level discounts (coupons, promotions) on those orders |
| Delivery charges | Σ `shipping_minor` |
| COD fees | Σ `cod_fee_minor` |
| Tax | Σ `tax_minor` + `order_tax_lines.tax_minor` (collected/billed; not "recognised" until delivered, see below) |
| Billed total | Σ `grand_total_minor` of non-cancelled orders |
| Collected | Σ `payments.captured_minor` for online payments by `paid_at` **+** Σ `cod_collections.amount_minor` by `collected_at` (COD counts as collected at courier delivery, not at remittance) |
| COD outstanding | COD collected by courier but not yet in a reconciled `cod_remittance` |
| Refunded | Σ `refunds.amount_minor` with status `succeeded` by completion date (manual refunds included) |
| Net sales | Gross sales − discounts − refunds of goods (by period of refund) ; delivery charges and tax reported separately |
| Net collected cash | Collected (online) + COD remitted − refunded cash |
| Recognised revenue | Physical: orders in `fulfilled` (delivered) state, at delivery date, net of discounts and returns, **excluding tax**; digital: at entitlement grant; services: at `completed`; refunds/returns reverse in refund period. Accounting treatment [NEEDS-ACCOUNTANT] |
| AOV | Billed total ÷ orders (non-cancelled) |
| Repeat-purchase rate | customers with ≥ 2 non-cancelled orders in window ÷ customers with ≥ 1 |
| Delivery SLA | delivered within configured days of handover ÷ shipments handed over |
| Failed-delivery rate | shipments `failed→returned` ÷ shipments handed over, by zone/courier |
| Payment success rate | `succeeded` transactions ÷ initiated (excluding user-cancelled) per method |
| Stock aging | days since last receipt per variant×location, valued at cost where cost data exists |
Cancelled orders appear in cancellation reports only. All dates use Asia/Dhaka day boundaries (stored UTC).

## 2. Report catalogue (filters: date range, channel, payment method, category, courier, locale)
Sales summary · by product/category/brand · trends · payment-method analysis · COD collection & remittance aging · pending/failed payments · refunds & cancellations with reasons · delivery performance · inventory availability/aging/low-stock/out-of-stock · best sellers · customer cohorts/repeat · coupon/campaign performance · service bookings utilisation/no-show · search zero-results · **tax summary** (collected by rate, credit notes; exportable for the accountant) · daily close sheet (collected vs refunded vs COD outstanding vs gateway/courier statements).

## 3. Data layer
Summary tables `report_daily_sales`, `report_daily_payments`, `report_daily_cod`, `report_daily_inventory`, etc. refreshed idempotently by jobs (per day, re-computable); event-driven increments where cheap; nightly full recompute for last N days. Exports: async job → signed expiring link; permission `reports.finance.view` / `customers.export`; export audit; PII masked unless `customers.view_pii`. No user-facing raw SQL builder.

## 4. Reconciliation tests
Seeded scenarios (partial refunds, coupons, COD, cancellations, partial shipments, tax inclusive/exclusive) → report totals equal ledger sums and hand-calculated expectations (Phase 10 gate).

## 5. Analytics events (privacy-respecting)
page_view, search, view_item, add_to_cart, begin_checkout, purchase — first-party, behind consent flag, no third-party scripts without approval; funnel by device and step feeds Phase 14.
