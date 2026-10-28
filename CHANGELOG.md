# Changelog

All notable changes. Format: [Keep a Changelog](https://keepachangelog.com), versioning to be decided before launch.

## [Unreleased]
### Added — Phase 3 (catalog, pricing, inventory core, admin UI)
- Catalog: closure-table categories, brands, tags, collections; bilingual translations with Unicode slugs; products (physical / digital / service) with SKU rules, attributes and options, variant generator (max 250), publish / schedule / unpublish / archive workflow, HTML sanitiser.
- Pricing: regular prices, DB-enforced non-overlapping scheduled sales (Dhaka-time windows), `PriceQuote` (tax-inclusive / exclusive), bulk change with preview and stale-preview guard; tax classes and dated, non-overlapping tax rates (tax stays off until VAT registration).
- Inventory: locations, levels with CHECKs, append-only ledger, atomic conditional-UPDATE reservations, ordered multi-line baskets, commit at shipment, expiry job, integrity verifier, low-stock event; proven with real multi-process concurrency tests.
- Media: content-sniffed uploads, decompression-bomb guard, re-encode (strips EXIF and hidden payloads), responsive WebP (+AVIF when supported), per-language alt text, gallery management, malware-scanner hook.
- Admin UI (en / bn): categories, brands, tags, collections, attributes; product list with filters and search, bilingual editor with missing-translation badges, variants (add / generate / delete), image manager, publish controls; price editor, sale scheduling, bulk price change with preview; stock levels, guarded manual adjustments, movement history.
- CSV import / export: bilingual columns, SKU-keyed idempotent upsert, dry run on the real code path, background job, per-row errors in en / bn, chunk-atomic apply, formula-safe export.
- Demo data: `DemoCatalogSeeder` (5 categories, 46 bilingual products, variants, one digital, one service; development only).
- New contracts for cross-module use: `VariantDirectory`, `PriceWriter`, `StockInitialiser`, `TaxRates::classCodes()`, `PriceCatalog::regularPrices()`.
### Changed during Phase 3
- `Money::toMajorString()` added (exact decimal for form fields).
- Route-authorization runtime test now fills route parameters from their `where` constraints.
### Added — Phase 2 (identity, access control, localisation)
- Identity: single `users` table (customer | staff), email-or-phone identity, argon2id, separate `web` / `admin` guards with account-type-scoped providers.
- Customer auth: email + password registration (enumeration-safe), password and phone-OTP sign-in (phone sign-in doubles as sign-up), lockout, password reset, email verification, device list and "sign out other devices", recent re-authentication for sensitive changes, password / email / phone change, profile + marketing consent, account-deletion request stub.
- OTP service with SMS-pumping guards (cooldown, per-number, per-IP, daily cap + alert), `SmsGateway` contract (Log / Fake adapters; SSL Wireless adapter pending official docs).
- Staff auth: separate login, mandatory TOTP with forced enrolment, recovery codes, replay protection, 30-minute idle timeout, device list, admin password reset / invite.
- RBAC: permission catalogue, 9 seeded roles, audited Super Admin bypass, escalation and last-Super-Admin guards, staff and role admin UI, `velmora:create-super-admin`, generated `docs/architecture/PERMISSION_MATRIX.md`.
- Localisation: URL-prefixed `/en` `/bn` routes, locale precedence (URL > user > cookie > Accept-Language > English), switcher, hreflang/canonical, lakh/crore + Dhaka-time formatter, full Bangla Laravel translations.
- Gate tests: every route has an explicit authorization decision (deny-by-default); en/bn key parity; no hard-coded Blade text.
### Fixed during Phase 2 (found by tests)
- TOTP wrapper rejected every first code (library returns `true`, not the step, when no previous step is given).
- Notifications declaring `$locale` collided with the framework's `Notification::$locale` (fatal on load).
- Extra session regeneration after login orphaned the device record; phone-created accounts had no in-memory status.

### Added — Phase 1 (foundation)
- Laravel 13.39 on MariaDB 12.1 (`mariadb` driver, UTC session, `utf8mb4_uca1400_ai_ci`), Pest 4, Larastan level 8, Pint.
- Module system: `app/Modules/*` auto-discovery, `make:module`, BD-11 boundary checker with a deliberately violating fixture.
- Platform kernel: `Money` (bigint minor units, exact allocation, en/bn formatting, lakh/crore), `PhoneNumber`, `BanglaText`,
  `Clock`, `Actor`/`RequestContext`, `HasPublicId`, `HasTranslations`, `Settings`, `AuditLogger` (append-only), `Redactor`,
  idempotency middleware, transactional outbox with idempotent consumers, `Transactions` (READ COMMITTED + bounded deadlock retry).
- Error handling (RFC 9457 problem+json with stable codes; bilingual web error pages), JSON log redaction, secure headers
  (CSP report-only), rate-limit tiers, `/healthz` + `/readyz`, production-config guard.
- Layouts, Tailwind tokens, self-hosted Noto Sans Bengali + Inter, Alpine.
- Docker Compose (Redis, S3 via SeaweedFS with `velmora-media` + `velmora-private` buckets, Mailpit; optional MariaDB-CI), Horizon, GitHub Actions CI, PR template.
- `media` (public) and `private` (signed-URL only) S3 disks; storage tests prove anonymous access to private objects is denied and signed URLs expire.
- Architecture package (`docs/`), AGENTS.md, backlog.
