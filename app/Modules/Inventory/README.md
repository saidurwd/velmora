# Inventory module

Responsibilities, owned tables, events emitted/consumed: see docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).
* Phase 10, slice 2: `Contracts/StockReportFigures` gives the Reporting module the stock lines that need attention (nothing left to sell, or at/below the low level) and the longest-sitting stock.
