# Phase 0 — Discovery & Feasibility

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | 5–8 person-days (mostly owner time: decisions, provider applications) |
| **Prerequisites** | None |
| **Attach** | `02_BASELINE_DECISIONS.md`, `Draft.md` |
| **Output** | Approved scope, personas, provider shortlist, decisions log, business paperwork started |
| **Exit gate** | Owner signs off Batch 1 of the master prompt (`01_MASTER_ARCHITECTURE_PROMPT.md`) |

**Relationship to the master prompt:** the AI part of Phase 0 *is* master-prompt Batch 1. This file adds the **owner's non-AI to-do list**, which has the longest lead times (gateway and courier onboarding can take weeks) and should start on day 1.

## Owner checklist (start immediately, in parallel)
- [ ] Decide legal entity / trade licence / TIN / BIN status for the selling entity (needed by most gateways).
- [ ] Open merchant/sandbox accounts: shortlist 1–2 aggregators (SSLCommerz, ShurjoPay, aamarPay) and ask each: refund API? bKash/Nagad via aggregator? settlement T+? fees? IPN signature method? sandbox?
- [ ] Contact 2 couriers (Pathao, Steadfast, RedX, Paperfly): API access, COD remittance cycle, return fees, webhook support.
- [ ] Pick SMS provider shortlist; start BTRC sender-ID/masking process if required (long lead time).
- [ ] Choose domain, hosting region, object-storage vendor, email provider.
- [ ] Gather: 20–50 real sample products (physical/digital/service), real Bangla product names, delivery-charge rules, return policy, COD policy.
- [ ] Book a Bangladesh lawyer/tax-adviser review slot for terms, privacy, refund policy and VAT invoicing.

## Copy-paste prompt (AI discovery session)

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

TASK: Run discovery only. No code.
Using Draft.md and 02_BASELINE_DECISIONS.md:
1. Produce user personas (at least: first-time Bangla-speaking mobile shopper on 3G; repeat urban shopper; digital-goods buyer; service customer; shop owner/admin; warehouse/packing staff; customer-support agent; accountant) with jobs-to-be-done and top frustrations.
2. Produce the end-to-end operational process maps (order-to-cash for physical COD, physical prepaid, digital, service; returns; refund; courier reconciliation; COD remittance).
3. Draft BUSINESS_REQUIREMENTS with numbered, testable requirements (REQ-xxx) and MoSCoW priority.
4. Draft the MVP boundary, using the classification in 02_BASELINE_DECISIONS.md §15 as the starting point.
5. Research payment gateways, couriers, SMS providers and legal items (see master prompt §5). Output comparison tables with an explicit "unverified" column.
6. Ask me at most 12 clarifying questions, each with your proposed default and the cost of being wrong.
7. Produce an initial RISK_REGISTER and a decisions log.

ACCEPTANCE CRITERIA
- Every requirement has an ID, priority, and acceptance test statement.
- No provider capability is stated as fact without a source URL + access date.
- Every ambiguity from Draft.md is either resolved or listed as an open question with a default.
- MVP list fits the effort estimates in the roadmap (flag if it does not).

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
