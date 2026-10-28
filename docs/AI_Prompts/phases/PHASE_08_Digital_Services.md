# Phase 8 — Digital Products & Service Bookings

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | 15–22 person-days |
| **Prerequisites** | Phase 6 (payments) — independent of Phase 7 |
| **Modules** | DigitalProducts, Services (Bookings), Orders/Notifications integration |
| **Attach** | `DATABASE_DESIGN.md` (digital + services), `SECURITY_AND_PRIVACY.md` (file handling) |
| **Exit gate** | Paid digital goods are delivered securely with audit; service bookings can never double-book a resource |

## Copy-paste prompt

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

OBJECTIVE
Support non-shippable catalog types with their own fulfilment rules — separate from physical inventory/shipping.

A. DIGITAL PRODUCTS
1. `digital_assets` (private bucket path, size, checksum, version, original filename), `entitlements`, `download_logs`, `license_keys` (pool; atomic allocation with `FOR UPDATE SKIP LOCKED` or conditional update).
2. Listener on `OrderPaid`/COD-not-applicable (digital items require prepaid unless an ADR says otherwise) → create entitlements; email/SMS link; "My Library" page; guest access via signed expiring link + OTP.
3. Download endpoint: authz → entitlement valid (status, limit, expiry) → increment counter atomically → short-lived signed URL / streamed response with `Content-Disposition`; log ip/ua; optional watermarking hook (PDF/EPUB, stub).
4. Versioning: new asset versions notify entitled customers; revoke on refund/chargeback (policy-driven); admin can extend/reset limits with audit.
5. Refund policy engine: configurable rules (e.g., non-refundable after first download) shown on PDP and checkout.

B. SERVICES & BOOKINGS
1. `service_definitions`, `service_packages` (duration, price, buffers), `service_resources` (staff/team/room/vehicle), `availability_rules` (weekly template + date exceptions + holidays; Asia/Dhaka), `service_bookings` with `tstzrange` + **EXCLUDE constraint** (BD §8), `booking_requirements` (customer inputs + attachments), `booking_events`.
2. Slot search Action (service, package, date range, optional resource) honouring buffers, lead time, max horizon, capacity>1 services (group sessions) if in scope.
3. Booking flow: select slot → **hold** (TTL) → checkout (reuse Phase 5/6) → confirm on payment/COD-equivalent policy → reminders (SMS/email, scheduled) → in-progress → completed (customer or staff confirms) → review prompt.
4. Cancel/reschedule with policy windows and fee/refund calculation (policy rows, not code); no-show handling; staff reassignment.
5. Location types: on-site (customer address within service areas/zone), at-provider, online (meeting link field).
6. Admin: calendar view (day/week), booking list/detail, availability editor, resource management, policy editor; customer-facing "My bookings".

SECURITY: attachment upload hardening; ownership checks on bookings/entitlements; signed link expiry; rate-limit downloads; no direct storage URLs.

TESTS (must pass)
- 20 parallel booking attempts for the same slot → exactly one confirmed (DB constraint is the guarantee).
- Hold expires → slot reopens; confirm after expiry rejected.
- DST-free but timezone-boundary tests (UTC↔Asia/Dhaka around midnight); buffers & lead-time rules.
- Cancellation fee/refund matrix across policy windows.
- Download without entitlement / expired / limit exceeded / other user's entitlement → denied and logged.
- Concurrent downloads at the limit boundary → counter never exceeds limit.
- License key allocation under concurrency → each key issued once.
- Refund revokes entitlement per policy.

ACCEPTANCE CRITERIA
- No code path exposes a permanent public URL for a digital file.
- Services never touch `stock_*` tables; digital goods never create shipments.
- All policy texts shown to customers come from configurable, translated records.

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
