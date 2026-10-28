# Phase 11 — Mobile API (v1)

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | 12–18 person-days |
| **Prerequisites** | Phases 5–9 complete (API wraps existing Actions) |
| **Modules** | All modules' `Http/Api` layers, IdentityAccess (tokens), Notifications (push) |
| **Attach** | `API_ARCHITECTURE.md`, `API_ENDPOINT_CATALOG.md`, `02_BASELINE_DECISIONS.md` §12 |
| **Exit gate** | A mobile developer can build the full shopping journey from the OpenAPI spec alone, with no business rules re-implemented client-side |

## Copy-paste prompt

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

OBJECTIVE
Expose the existing business Actions through a stable, versioned, documented REST API — **without duplicating any business rule**.

TASKS
1. **Foundation:** `/api/v1`, `Accept-Language` locale handling, JSON envelope, RFC 9457 errors with stable codes catalogue, cursor/page pagination, filtering/sorting conventions, ETag/conditional GET on catalog, gzip/brotli, request-id header, `Idempotency-Key` middleware on critical POSTs, rate-limit tiers per route group with `Retry-After`.
2. **Auth:** register/login by email+password and phone+OTP, token issue (Sanctum, abilities, device name), refresh/rotation strategy per ADR, logout/revoke current & all, token listing, forced re-auth for sensitive actions, device registration for push (FCM token table), app-version gate (`min_supported_version` response + 426 for dead versions).
3. **Endpoints (each = thin controller → existing Action):** config/bootstrap (languages, currency, payment methods, policies, min app version); catalog (categories, products, variants, filters, search + autocomplete, recently viewed server-side optional); cart (CRUD, coupon apply, shipping quote); addresses + geography; checkout (quote, place order); payments (initiate, status; return via deep link/app-link; **status always from server**); orders (list/detail/tracking/cancel/return request/invoice URL); digital library + signed download URL; bookings (slots, hold, confirm, cancel); wishlist; reviews; notifications inbox + preferences; support tickets; profile & privacy requests.
4. **Resources:** dedicated API Resource classes (never expose internal ids/columns); money returned as `{amount_minor, currency, formatted}`; localized fields resolved server-side with `locale` echoed; image URLs with size variants.
5. **Documentation:** OpenAPI 3.1 generated + hand-annotated examples; CI check that the committed spec equals generated spec; Postman/Bruno collection; **mobile integration guide** (auth flow, payment deep-link flow, offline/retry guidance, error-code handling, pagination, image sizes, push payloads); changelog + deprecation policy doc.
6. **Contract tests:** every endpoint validated against the OpenAPI schema in tests; golden-file tests for key responses (bn and en).
7. **Push notifications:** FCM integration behind the `Push` channel contract; topic/segment strategy; payload size/localisation; opt-in management.
8. **Abuse controls:** per-token and per-IP limits, strict limits on OTP/auth/search, bot/velocity signals feeding fraud rules.

SECURITY: token storage guidance for mobile (Keychain/Keystore), certificate-pinning guidance (optional, with rotation plan), no sensitive data in push payloads, ownership checks via the same Policies as web, mass-assignment-proof resources.

TESTS (must pass)
- Parity tests: for each critical flow (place order, apply coupon, cancel, book) assert Web and API produce identical domain results (same Action, same totals, same events).
- Idempotent checkout via API under retry storms; payment status endpoint never reports paid before verification.
- IDOR suite: every `{id}` endpoint tested with another user's resource.
- Rate-limit and token-revocation behaviour; expired/rotated token handling.
- OpenAPI conformance on all responses; 100 % endpoints documented.
- Localisation: `Accept-Language: bn` returns Bangla content/messages with fallback.

ACCEPTANCE CRITERIA
- Breaking change detection in CI (spec diff gate).
- A new mobile client needs zero server-side changes for the MVP journey.

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
