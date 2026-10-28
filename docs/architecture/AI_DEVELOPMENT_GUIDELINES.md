# AI Development Guidelines (Batch 7)

Applies to Claude Code, Codex or any repo-aware agent. The operative short version lives in the repo-root `AGENTS.md`; this document is the rationale and workflow.

## 1. Sources of truth (highest first)
1. `docs/architecture/ARCHITECTURE_DECISION_RECORDS.md` and `DATABASE_ENGINE_DECISION.md` (ADR-000 overrides every PostgreSQL mention in `docs/AI_Prompts/*`).
2. `docs/architecture/*` (design docs) and `docs/spikes/*` (evidence).
3. `docs/AI_Prompts/phases/PHASE_nn_*.md` (task lists/tests/acceptance) — read with the MariaDB translation table.
4. Official provider docs saved in `docs/vendor/<provider>/` (access date in header).
Conflict between sources ⇒ **stop and ask**; never choose silently.

## 2. Workflow per task
Read → plan (files, tables, events, permissions, tests, bn+en strings, risks) → **human approval for money/stock/auth/schema** → failing tests first for risky logic → smallest vertical slice (migration → model → Action → policy → request → controller/resource/view → lang) → run format, static analysis, arch tests, module tests, full suite → check N+1/indexes/authz/bn+en/redaction → update docs/ADR/OpenAPI/CHANGELOG → small Conventional Commits → honest report (04_FEATURE_SLICE_PROMPT format).

## 3. Rules of conduct
- Inspect before changing; never delete/overwrite what you don't understand.
- Do not report tests as passing unless executed; paste real results.
- Never weaken tests, validation, authz or security to go green.
- Never hard-code secrets; never touch live payment/courier/SMS; never run destructive DB operations on `velmora` without explicit approval; tests use `velmora_test` only (it is wiped).
- Dependencies need a written reason (maintenance, licence, security).
- MariaDB specifics: DDL auto-commits — one purpose per migration; use READ COMMITTED for write transactions; `INSERT IGNORE`/conditional `UPDATE` patterns per `DATABASE_DESIGN.md` §5; no PostgreSQL-only SQL.
- Keep module boundaries (Contracts/DTOs/Enums/Events only across modules); arch tests are law.
- Ask a human before: irreversible data ops, destructive migrations, production deploys, live-money/courier/SMS actions, changing a pinned/accepted ADR, major dependencies, conflicts with docs.

## 4. Session hygiene
New session per phase; the repo and docs are the memory. Review-only second session (different agent/model) for money, stock, auth and migration code. Update `CHANGELOG.md`, ADRs and the backlog in the same PR as the code. Record deviations as ADRs.

## 5. Report format
1) Summary 2) Files changed 3) Commands run + real results 4) Acceptance-criteria checklist with evidence 5) Deviations (ADRs) 6) Known gaps/tech debt 7) Questions/decisions needed.
