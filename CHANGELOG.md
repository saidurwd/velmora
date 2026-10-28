# Changelog

All notable changes. Format: [Keep a Changelog](https://keepachangelog.com), versioning to be decided before launch.

## [Unreleased]
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
