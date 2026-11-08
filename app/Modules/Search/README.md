# Search module

Responsibilities, owned tables, events emitted/consumed: see docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).
* Phase 10, slice 2d: `Contracts/SearchFigures` gives the Reporting module the repeated searches that found nothing, and the count of all of them. Search text is typed by customers, so the report decides what may be shown.
