# ADR-000 — Database engine: MariaDB / MySQL instead of PostgreSQL

Status: **Accepted by owner, 2026-10-28** (overrides pinned BD-02) · Supersedes PostgreSQL wording everywhere in `AI_Prompts/*`.

## Context
Pack baseline pinned PostgreSQL (BD-02) and several other pinned decisions rely on PostgreSQL-only features. Owner instruction: use MySQL/MariaDB. Existing database `velmora` is on a local **MariaDB 12.1.2** server (verified via `mysql -e "select version()"`).

## Decision
1. **Target engine: MariaDB (≥ 11.4 LTS; dev currently 12.1.2)** with InnoDB, `utf8mb4`. Keep schema/SQL portable to **MySQL 8.4 LTS** where it costs little; features that only one engine has are listed below and need an owner choice (Q13 — resolved: **MariaDB**).
2. Laravel uses the `mariadb` driver (or `mysql` if MySQL is chosen). Tests run on a real MariaDB/MySQL `velmora_test` — **never SQLite**.
3. Dev DB: existing `velmora`; test DB `velmora_test` created 2026-10-28 with owner approval (utf8mb4 / utf8mb4_uca1400_ai_ci, same as `velmora`).

## Impact on pinned decisions (every row needs re-pinning in the Phase 1 ADRs)
| Pinned item | PostgreSQL design | MariaDB/MySQL replacement | Risk |
|---|---|---|---|
| BD-02 engine | PG ≥16 | MariaDB ≥11.4 / MySQL 8.4 | — |
| BD-21 ids | bigint + ULID | `BIGINT UNSIGNED` + `CHAR(26) ascii_bin` unique | Low |
| BD-22 time | `timestamptz` | `DATETIME(6)` storing UTC, connection tz `+00:00` (avoid `TIMESTAMP` 2038 limit) | Low |
| BD-24 enums | varchar + CHECK | same; CHECK enforced MariaDB ≥10.2, MySQL ≥8.0.16 | Low |
| BD-25 CHECK/FK | same | same (InnoDB) | Low |
| BD-26 JSONB | JSONB | `JSON` (MariaDB = validated LONGTEXT, not indexable); queried fields → real/generated columns | Low |
| BD-28 order no. | DB sequence | MariaDB `SEQUENCE` object; MySQL: counter table with `FOR UPDATE` | Low |
| BD-30 translations | tables + unique(locale,slug) | same | Low |
| Hierarchy (`ltree`) | ltree/closure | **closure table** only | Low |
| Partial indexes (`published`, `status='pending'`) | partial index | not supported → composite index with status first, or generated-column unique trick | Low–Med |
| Stock reserve (BD §6) | conditional `UPDATE` | **identical**; check affected-rows. Lock order by `variant_id`. Deadlock = errno 1213, lock wait 1205; bounded retry | Low |
| `FOR UPDATE SKIP LOCKED` (license keys) | PG | MariaDB ≥10.6, MySQL ≥8.0 | Low |
| Isolation | READ COMMITTED default | InnoDB default REPEATABLE READ → gap-lock deadlocks; **use READ COMMITTED for order/payment transactions** (ADR) | Med |
| **Booking no-double-book (BD §8)** | `EXCLUDE USING gist` + `tstzrange` | **No exclusion constraints.** Options: (a) MariaDB application-time period `UNIQUE (resource_id, period WITHOUT OVERLAPS)` — MariaDB-only, no partial `WHERE`, so cancelled rows must leave the table [UNVERIFIED, verify before relying]; (b) discrete slot grid `UNIQUE(resource_id, slot_start)` — portable, DB-enforced, needs fixed granularity; (c) lock resource row + overlap check — weakest, app-level. **Recommend (b), or (a) if MariaDB is final.** Needs spike | **High** |
| Search (`tsvector`, `pg_trgm`, `unaccent`) | FTS + trigram | InnoDB `FULLTEXT` (MySQL has `ngram` parser; MariaDB has none built in); LIKE/prefix on normalised columns; Bangla tokenisation weak | **High** — proposal: bring forward Meilisearch/Typesense (reverses "no search engine by default") via ADR + spike |
| Append-only ledgers | privileges | privileges + `BEFORE UPDATE/DELETE` triggers that `SIGNAL` | Low |
| Migrations | transactional DDL | **DDL auto-commits**; a failed migration can leave partial state → small single-purpose migrations, expand/contract strictly | Med |
| PITR/backups | WAL + base backup | binlog + `mariadb-backup`/Percona XtraBackup | Low |
| Collation/Bangla sort | ICU/libc | `utf8mb4_unicode_520_ci` / MariaDB `uca1400_ai_ci`; test Bangla ordering | Low |
| Monitoring | `pg_stat_statements` | slow query log, `performance_schema` | Low |
| Managed hosting | many PG options | MySQL widely managed; MariaDB managed less common — check Q7 | Med |

## Consequences
- Phase 1 DB ADRs, Phase 3 (search prep, hierarchy), Phase 4 (search — largest change), Phase 8 (bookings — second largest) prompts must be re-read with the table above; stale PG wording in those prompts is overridden by this ADR.
- Two spikes added before Phase 3: **S1 booking overlap constraint**, **S2 Bangla search engine choice**.

## Reversal
Cost rises with each phase; decide MariaDB vs MySQL before Phase 1 scaffold.
