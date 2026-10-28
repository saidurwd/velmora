# Changelog

All notable changes. Format: [Keep a Changelog](https://keepachangelog.com), versioning to be decided before launch.

## [Unreleased]
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
