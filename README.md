# Velmora

Bilingual (English default, Bangla) e-commerce platform for Bangladesh — physical products, digital products and services.
Laravel 13 · Blade + Tailwind 4 + Alpine · **MariaDB** · Redis · modular monolith · versioned REST API for future mobile apps.

> Start with `AGENTS.md` (rules for people and AI agents) and `docs/architecture/` (the approved design).
> Roadmap: `docs/architecture/PHASE_WISE_DEVELOPMENT_ROADMAP.md` · Backlog: `docs/backlog/BACKLOG.md`.

## Requirements
PHP 8.3+ (8.4 used), Composer 2, Node 22, Docker (for Redis/Mailpit), MariaDB ≥ 11.4 (dev server 12.1.2).

## Quick start
```bash
git clone <repo> && cd velmora
docker compose up -d                 # redis :6390, S3 (SeaweedFS) :9010 + buckets, mailpit :1035 (UI :8035)
composer install && npm ci
cp .env.example .env && php artisan key:generate
#  edit .env: DB_USERNAME / DB_PASSWORD for your MariaDB; the database `velmora` must exist
php artisan migrate --seed           # platform tables + reference data (languages)
npm run build                        # or `npm run dev`
php artisan serve                    # http://127.0.0.1:8000  (/healthz, /readyz)
php artisan horizon                  # queue workers (Redis) — in a second terminal
```
Scheduler (outbox dispatch, heartbeat): `php artisan schedule:work`.

### Test database
Tests run on a **real MariaDB** schema named `velmora_test` (never SQLite) and **wipe it**. `tests/TestCase.php` refuses to run
against any database whose name does not end in `_test`. Create it once:
```sql
CREATE DATABASE velmora_test CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
```
> Never run `migrate:fresh`, `db:wipe` or `--env=testing` commands against `velmora` unless you mean to destroy dev data.

## Everyday commands
| Task | Command |
|---|---|
| Full gate (what CI runs) | `composer check` (Pint → Larastan level 8 → all tests) |
| Tests | `php artisan test` · one suite: `--testsuite=Architecture` · filter: `--filter=Money` |
| Style | `vendor/bin/pint` (fix) / `composer lint` |
| Static analysis | `composer analyse` |
| New module | `php artisan make:module Orders` |
| Frontend | `npm run dev` / `npm run build` |

## Layout
```
app/Modules/<Module>/   business code (Actions, Contracts, DTOs, Enums, Events, Models, Http, Providers, routes, resources, Database, tests)
app/Modules/Platform/   shared kernel: Money, PhoneNumber, BanglaText, Clock, Settings, AuditLogger, Idempotency, Outbox, health, error handling
docs/architecture/      approved design + ADRs      docs/spikes/   evidence      docs/backlog/   tasks
tests/Architecture      module-boundary + localisation rules      tests/Database   real-transaction / DDL / concurrency tests
```
Modules talk to each other only through **Contracts, DTOs, Enums and Events** — enforced by `tests/Architecture`.

## Ports (private range to avoid clashes with other local projects)
Redis 6390 · S3 (SeaweedFS) 9010 · Mailpit SMTP 1035 / UI 8035 · optional MariaDB-CI 3317 (`--profile ci-parity`).
MinIO is not used: it no longer publishes public container images. Dev S3 credentials in `.env.example` are throwaway.
