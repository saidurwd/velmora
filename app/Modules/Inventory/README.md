# Inventory module

Responsibilities, owned tables, events emitted/consumed: see docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).
* Phase 10, slice 2: `Contracts/StockReportFigures` gives the Reporting module the stock lines that need attention (nothing left to sell, or at/below the low level) and the longest-sitting stock.
* Phase 10, slice 4b: `Integrity/LedgerMatchesLevelsCheck` and `ReservationsMatchCheck` (read-only, registered with the Platform integrity registry) report stock that does not match the ledger or the reservations.
* Phase 10, slice 5a: `Dashboard/LowStockTile` (from `StockReportFigures`, capped at 100).
* Phase 10, slice 5b: stock files — `Actions/PreviewStockImport` (dry run, row by row), `ApplyStockImport` (once, all or nothing, through `AdjustStock`) and `RollbackStockImport` (compensating ledger rows, only for products not changed since); tables `stock_imports` and `stock_import_rows`; `/admin/inventory/imports` needs `inventory.adjust`. `inventory:ops-probe` gained `apply-import` and `rollback-import` for the concurrency tests.
