# Deployment and DevOps (Batch 6 draft)

SMS (Q5): **SSL Wireless ISMS Plus** (requires registered Sender ID and a whitelisted server IP — the production egress IP must be stable/static; add to go-live checklist). Hosting (Q7) and email provider are **unanswered → defaults logged as assumptions**: Linux VPS/cloud, region nearest Bangladesh latency-wise, MariaDB (self-managed or managed MariaDB), Redis, S3-compatible storage. Data-residency obligations [NEEDS-LAWYER]. Managed-MariaDB availability varies by provider — check before choosing.

## 1. Environments
| Env | Purpose | Data | Providers |
|---|---|---|---|
| local | dev (Docker Compose: php-fpm, nginx, MariaDB, Redis, SeaweedFS (S3-compatible), Mailpit) | seeded demo; current dev DB `velmora`, tests `velmora_test` | fakes/log |
| CI | ephemeral | factories | fakes |
| staging | prod mirror | anonymised/synthetic | provider sandboxes |
| production | live | real | live (human cutover) |

## 2. CI/CD
GitHub Actions: build once (composer install --no-dev, assets build) → artefact → deploy. Branch protection; required checks (Testing doc §4); tagged releases; changelog. Deploy: zero-downtime — new release dir, `php artisan migrate --force` (expand-only), queue drain/restart (`horizon:terminate`), health-gated symlink switch, instant rollback = previous release (migrations backward-compatible because contract steps ship in a later release). Because MariaDB DDL is non-transactional, migrations are single-purpose and rehearsed on staging with production-sized data.

## 3. Configuration and secrets
`.env.example` documents every variable; boot-time validation fails fast on missing secrets; secrets in a manager/vault or encrypted env, never in repo; provider credentials entered by a human in admin (encrypted) or env at go-live; rotation procedure documented. DB roles: `app` (DML, no DDL, no UPDATE/DELETE on append-only tables), `migrator` (DDL), `readonly_reporting`.

## 4. Backups
Daily full + continuous binlog (PITR) using mariadb-backup or equivalent; encrypted, off-site; object-storage versioning; retention 35 d daily / 12 m monthly [ASSUMPTION]; **monthly automated restore verification**; documented RTO ≤ 4 h / RPO ≤ 15 min.

## 5. Monitoring and alerting
Uptime checks (storefront, checkout, webhook endpoints); error tracking; APM and slow-query alerts; Horizon/queue lag; scheduler heartbeat; metrics: orders/min, payment success rate, pending payments age, callback failure/signature failures, mismatch count, refund failures, deadlock retries, SMS spend, stock-ledger integrity job, disk/CPU/memory, DB replication (if any). Alert routing with severity and escalation (on-call rota is a bus-factor mitigation).

## 6. Runbooks (to write in Phase 12/13)
Payment stuck · gateway outage (payment-freeze) · courier outage (ManualCourier) · stock mismatch · SMS provider failure · queue backlog · DB failover/restore · rollback · secret leak · pen-test finding triage · COD fraud spike.

## 7. Deployment architecture
```mermaid
flowchart TB
  DNS --> CDN --> LB[Nginx / LB]
  LB --> A1[App node 1: php-fpm]
  LB --> A2[App node 2 - later]
  A1 & A2 --> DB[(MariaDB primary)]
  DB -.binlog.-> BK[(Encrypted backups off-site)]
  DB -.replica later.-> RR[(Read replica: reports)]
  A1 & A2 --> RD[(Redis)]
  W[Queue workers + scheduler] --> DB
  W --> RD
  A1 & A2 & W --> OS[(Object storage: public media / private digital)]
  MON[Monitoring + alerts] --- A1
```

## 8. Rollout and cutover (Phase 13)
Soft launch (staff/friends) → limited audience (flags/invite) → public. Live provider cutover per provider: credentials entered by human, callback URLs/IPs whitelisted, controlled live smoke test with owner-authorised minimal amount incl. refund, rollback to "COD only". Hypercare: 14 days with twice-daily dashboard review and daily payment/COD reconciliation. The AI prepares scripts and checklists; **a human executes anything touching production or real money**.

## 9. Go-live checklist (summary)
Phase 12 go decision · restore rehearsal passed · security findings Critical/High = 0 · policies reviewed by counsel · tax settings confirmed by adviser · providers approved · alerts verified firing · rollback rehearsed · support SOPs ready · payment-freeze < 2 min · DNS/TLS ready.
