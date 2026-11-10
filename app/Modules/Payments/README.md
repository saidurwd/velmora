# Payments module

Responsibilities, owned tables, events emitted/consumed: see docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).
* Phase 10, slice 1: `Contracts/PaymentFigures` gives the Reporting module the payments paid and the refunds succeeded in a time window (order, method, amount). New indexes `payments(paid_at)` and `refunds(status, updated_at)`.
* Phase 10, slice 2: `Contracts/CodAgingFigures` gives the Reporting module the cash-on-delivery aging (uncollected and unremitted cash by age) as lines.
* Phase 10, slice 4b: `Integrity/RefundsWithinCapturedCheck` (read-only) reports payments whose live refunds exceed the money received.
* Phase 10, slice 4c: `GatewayRegistry::registerResolver` lets SSLCommerz be decided on first use from the credentials in force (saved by staff or from the server); `registerSslCommerz()` and the live rule are unchanged. `Support/SslCommerzConnectionTest` queries the SANDBOX for a made-up transaction id and never runs in live mode.
* Reason codes: `Enums/RefundReason`; `RequestRefund` needs one and stores it on the refund (`refunds.reason_code`). `Contracts/RefundReasonFigures` gives Reporting succeeded refunds per code.
