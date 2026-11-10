# Pricing module

Responsibilities, owned tables, events emitted/consumed: see docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).
* Phase 10, slice 2c: `Contracts/CouponFigures` streams the coupon uses of a time window (code, order id, discount) to the Reporting module, in pages by id; index `coupon_redemptions(redeemed_at, coupon_id)`.
* Phase 10, slice 4b: `Integrity/CouponUseCountsCheck` (read-only) reports coupons whose use count differs from their redemptions.
