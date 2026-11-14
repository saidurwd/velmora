# Production readiness report — Phase 12 (go / no-go)

Date: 2026-11-13 · Prepared by Claude as the sceptical reviewer · Status: **NO-GO for real customers and real money today; GO for a staging environment.**

The code and the tests are in good shape, and the review found and fixed real problems (below). What blocks launch is almost entirely things that need a real environment or other people: they are listed in section 3 with an owner.

## 1. What was done, with evidence

| Phase 12 task | Result | Evidence |
|---|---|---|
| 1 Regression and browser journeys | **Done on the local stack.** 100 browser tests (axe accessibility in English and Bangla, keyboard-only use, no-JavaScript use, checkout, account, staff screens, the new CSP checks) pass with the security policy enforced; the full unit/feature/architecture suite and the 119-test concurrency suite pass. Real gateway and courier sandboxes were **not** exercised. | `e2e/*.spec.js`; CI "Browser tests" green; full-suite runs in the session log |
| 2 Security review | **Done for the code** (not an independent audit or a penetration test). 12 findings: everything Critical to Medium is fixed; open: Info-level accepted trade-offs (F-5, the staff layout on a phone, is fixed). | `SECURITY_FINDINGS_REGISTER.md` |
| 3 Payments hardening drill | **Partly.** The replay, duplicate-notification, late-success, mismatch, refund-over-captured and reconciliation tests exist and pass; alert signals are tested. No chaos run against a real gateway (timeouts, callback storms, clock skew), no check that an alert reaches a person. | `tests/Database/PaymentConcurrencyTest`, `RefundConcurrencyTest`, `ReconciliationConcurrencyTest`; Payments feature tests |
| 4 Concurrency and integrity audit | **Done at development size.** All concurrency tests pass in a clean run (119 passed, 2 opt-in skipped); the 6 integrity checks pass on seeded data and on a restored copy. Not run on a production-sized data set or under external load. | `tests/Database/*` (22 minutes); `RESTORE_TEST_REPORT.md` |
| 5 Performance | **First pass only.** k6 on a laptop: inside the targets at light load, saturated at about 27 requests/s on that machine; no code-level problem; found one real configuration bug. The real test needs staging. | `PERFORMANCE_REPORT.md`; `tests/performance/storefront-api.k6.js` |
| 6 Accessibility | **Automated only.** Axe (WCAG 2.2 AA tags) clean on storefront and staff screens in both languages; keyboard flows tested. No manual screen-reader pass, no low-end Android check of Bangla text. | `e2e/a11y.spec.js`, `admin.spec.js`, `keyboard.spec.js`; `docs/qa/PHASE8_STAFF_SCREENS_A11Y_CHECKLIST.md` (to be walked by a person) |
| 7 Backup and restore | **Mechanics proved, targets not.** Dump and restore lose nothing and keep all 110 protective triggers; no point-in-time recovery, object storage, timing at real size or off-site copy tried. | `RESTORE_TEST_REPORT.md` |
| 8 Operational readiness | **Runbooks written (11), not rehearsed.** No on-call routing, log retention, dashboards or status page exist yet (hosting dependent). | `docs/runbooks/` |
| 9 Content and legal | **Not done; needs people.** Policy texts, cookie wording review, tax-adviser invoice review, seller disclosures. | `OWNER_LAUNCH_CHECKLIST.md` |
| 10 This report | You are reading it. | |

## 2. Acceptance criteria

| Criterion | Status |
|---|---|
| Zero open Critical/High security findings; Mediums fixed or accepted with a date | **Met for what was reviewed** (nothing open above Low). An external penetration test may add findings. |
| Performance targets met or formally waived | **Not yet decidable** (laptop only). |
| Restore rehearsal within RTO/RPO | **Not met / not rehearsed on real hosting.** |
| The 17 high-risk scenarios have passing automated tests | **Met by existing tests, mapped below; two are not individually verified (11, 15).** |

### The 17 mandatory scenarios (TESTING_AND_QA_STRATEGY §3) and where they are tested

| # | Scenario | Test evidence |
|---|---|---|
| 1 | Two buy the last unit | `tests/Database/CheckoutConcurrencyTest`, `InventoryConcurrencyTest`, `ApiCheckoutConcurrencyTest`; `InventoryTest` "takes the last unit exactly once" |
| 2 | Duplicate payment callbacks | `tests/Database/PaymentConcurrencyTest` |
| 3 | Payment succeeds, app times out | reconciliation: `ReconciliationConcurrencyTest`, `SslCommerzFlowTest` ("leaves everything waiting when SSLCommerz cannot be asked") |
| 4 | Failed payment then retry | `ApiPaymentsTest` "stays unpaid when the gateway says failed, and the person can try again" |
| 5 | Cancel races fulfilment | `ShipmentConcurrencyTest`, `OrderConcurrencyTest`; `CreateShipmentTest` "stops an order from being cancelled once a parcel exists" |
| 6 | Refund over captured | `RefundConcurrencyTest`; `RefundTest` "refuses a total refunded that is more than was received" |
| 7 | Coupon beyond limit | `CouponConcurrencyTest` |
| 8 | Another customer's order | cross-customer tests in every customer module (`AddressBookTest`, `ReturnsTest`, `TrackingTest`, the `Api*Test` files), `AccountOrdersConcurrencyTest`, deny-by-default route tests |
| 9 | Download without entitlement | `EntitlementConcurrencyTest`, `EntitlementsTest`, `ApiLibraryTest` |
| 10 | Booking slot conflict | `BookingConcurrencyTest`, `ApiHoldConcurrencyTest` |
| 11 | Callback arrives before the browser returns | **not individually verified**; the payment flow tests converge on one verified transition |
| 12 | Late callback after expiry | `OnlineOrderFlowTest` "does not reopen a cancelled order when money turns up late" |
| 13 | Amount/currency mismatch | `OnlineOrderFlowTest` "holds a mismatched payment for a person" |
| 14 | Out-of-order courier messages | `CourierEventsTest` "follows the courier's time, not the order the messages arrive in" |
| 15 | OTP brute force / SMS pumping | **not individually re-verified**; attempt caps and daily cap are in `OtpService`, per-address limits in `ApiThrottleTest` |
| 16 | Bangla digit input | `PaymentsAdminTest`, `CodTest`, `ShippingAdminTest` |
| 17 | Missing translation falls back | `BookingCancellationTest`, `TaxonomyTest`, the localisation architecture tests |

## 3. What blocks launch (and who can unblock it)

| # | Blocker | Owner |
|---|---|---|
| B1 | **External penetration test** and fixing what it finds | Owner (commission), then development |
| B2 | **Restore rehearsal on real hosting**: timed restore of a production-sized backup, point-in-time recovery, object storage, off-site encrypted copies | Server administrator |
| B3 | **Real payment sandbox run**: SSLCommerz sandbox credentials, the full online flow, a refund with a second approver | Owner (credentials), then development |
| B4 | **Performance on production-like staging** with the saved k6 scenario and a million-order data set for admin lists | Server administrator |
| B5 | **Production environment set-up** from `OWNER_LAUNCH_CHECKLIST.md`: queue worker, scheduler, `TRUSTED_PROXIES`, secrets, SMS, push, alert routing, log retention, status page | Server administrator / owner |
| B6 | **Policies and tax**: real policy texts reviewed by counsel; tax adviser sign-off on invoices (tax stays off until then) | Owner |
| B7 | **Manual accessibility pass** (screen reader, low-end Android Bangla rendering) and the staff checklist | Owner / a tester |
| B8 | **Runbook rehearsal**: run a payment-stuck and a gateway-outage drill on staging | Owner with development |
| B9 | **Courier**: Steadfast/Pathao documents saved so adapters can be built; until then parcels are booked by hand (documented) | Owner |

## 4. Residual risks (accepted knowingly)

* F-9 (a person can be locked out of sign-in for 15 minutes by repeated wrong attempts on their account, the usual trade-off against password guessing); F-8 (the queue dashboard is available only in local development).
* Everything real-world: gateway behaviour, courier behaviour, human processes. The code is tested against fakes and recorded shapes of the official documents, never against live services.

## 5. Recommendation

1. Stand up **staging** now (production-like, sandbox providers) and close B3, B4, B5, B8 there.
2. Commission the **penetration test** against staging in parallel (B1); fix findings with regression tests.
3. Run the **restore rehearsal** (B2) as soon as hosting exists.
4. Owner work (B6, B7, B9) in parallel; none needs code.
5. Re-run this report's checks, update it, and decide go/no-go with the owner's written sign-off.
