# Cart module

Responsibilities, owned tables, events emitted/consumed: see docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).
* Phase 9, slice 2: `Privacy/CartPersonalData` removes a signed-in customer's cart on erasure.
