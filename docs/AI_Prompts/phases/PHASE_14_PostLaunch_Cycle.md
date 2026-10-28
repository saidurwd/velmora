# Phase 14 — Post-Launch Improvement Cycles (repeatable)

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | Ongoing; run as 2-week cycles |
| **Prerequisites** | Production live, 2–4 weeks of real data |
| **Attach** | Production metrics export, support ticket themes, `RISK_REGISTER.md`, `04_FEATURE_SLICE_PROMPT.md` |

Phase 14 is a **loop**, not a build. Candidate epics (pick by evidence, not enthusiasm): conversion optimisation (checkout drop-off, search zero-results), more payment methods (direct bKash/Nagad, cards), more couriers + smart courier selection, advanced search (Meilisearch/Typesense *only if* targets missed), loyalty/points, referral, push campaigns, subscriptions & recurring billing, gift cards, B2B/wholesale price lists, pre-orders/backorders, multi-warehouse routing, marketplace (vendors, commissions, settlements), additional languages, international shipping & multi-currency, personalisation.

## Copy-paste prompt (cycle planner)

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

TASK: Plan the next 2-week improvement cycle. Do not code yet.
1. Analyse the attached metrics (funnel conversion by step and device, payment success by method, COD delivery/return rate by zone/courier, search zero-result rate, support ticket categories, page-speed field data, error budget, refund rate).
2. Identify the 3–5 highest-impact problems with evidence. For each: hypothesis, proposed change, expected metric movement, effort (person-days), risk, how to A/B or measure, rollback.
3. Check each proposal against the risk register and module boundaries; flag any that need an ADR (new dependency, new infrastructure, schema redesign).
4. For epics that are large (marketplace, subscriptions, B2B), produce a **feasibility spike plan** first: what must be true in the current schema, cost of retrofit, migration path.
5. Output a prioritised cycle backlog formatted for `04_FEATURE_SLICE_PROMPT.md`, and a "do not build yet" list with the trigger metric that would justify each.

ACCEPTANCE CRITERIA
- Every item has a measurable success metric and a stop/rollback condition.
- No item re-introduces cross-module coupling or bypasses pinned decisions without an ADR.

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
