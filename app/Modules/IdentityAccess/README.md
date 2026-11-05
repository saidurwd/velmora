# IdentityAccess module

Responsibilities, owned tables, events emitted/consumed: see docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).
* Phase 9, slice 2: `Privacy/AccountPersonalData` exports the account and, last, erases it (name, phone, password removed, email made an unusable `.invalid` address, disabled, sessions and tokens ended); staff accounts are never erased this way. New permissions `privacy.view` / `privacy.manage` (sensitive).
