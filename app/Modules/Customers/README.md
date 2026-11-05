# Customers module

Responsibilities, owned tables, events emitted/consumed: see docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).
* Phase 9, slice 2: the profile page no longer sets marketing consent (see Notifications `/account/preferences`); `CustomerProfiles::findManyByUserIds` for staff lists; `Privacy/CustomerPersonalData` exports and erases the profile and addresses.
