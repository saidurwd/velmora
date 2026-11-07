# Reporting module

Responsibilities, owned tables, events emitted/consumed: see docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).

* Phase 10, slice 1: **`Metrics/`** holds every metric once (`MetricDefinition`, one class each, and `MetricCatalogue`); dashboards, reports and exports ask the catalogue. The explaining words are in `resources/lang/{en,bn}/metrics.php`.
* **Summary tables** `report_daily_sales`, `report_daily_payments`, `report_day_status` (see the data dictionary). `Actions/RefreshReportDay` replaces one Dhaka day as a whole, under the day's lock; it reads Orders' `SalesFigures` and Payments' `PaymentFigures` Contracts only. `Jobs/RefreshRecentReportsJob` (every 15 minutes: today and yesterday) and `Jobs/RefreshWindowReportsJob` (02:45: the last 35 days); `php artisan reports:rebuild FROM TO` for older days (audited).
* Screen: `/admin/reports/sales` (`reports.view`; `reports.finance.view` for money received, given back and net sales). `Services/ReportReader` reads the summary only; `Services/ReportDays` turns a Dhaka day into UTC bounds. Settings in `config/reporting.php`.
* Recognised revenue is not built: the accountant has to confirm the rule first.
