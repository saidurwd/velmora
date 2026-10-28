# Definition of Done (Batch 7)

## Task level
Acceptance criteria met with evidence · tests (unit + feature; + concurrency/security/IDOR where relevant) written first for risky logic and passing on real MariaDB · Pint + Larastan + architecture tests clean · en **and** bn strings present, parity test green, both locales rendered · authorization verified (route-permission test) · no N+1 (query-count assertion on lists) and new queries EXPLAINed · migrations single-purpose, forward-only, tested up on realistic data · audit events for admin/financial/stock changes · logs redacted · docs (dictionary, endpoint catalog/OpenAPI, ADR, CHANGELOG) updated · no secrets · honest report.

## Phase level
All tasks done · phase acceptance criteria and exit gate demonstrated · full suite + CI green on clean clone · security review of the phase's surface · performance budgets for the phase checked · demo to owner · open defects triaged (no P0/P1) · backlog and risk register updated · human sign-off recorded in the decisions log.

## Release (Phase 12–13)
Zero open Critical/High security findings; targets met or waived in writing; restore rehearsal within RTO/RPO; all 17 mandatory scenarios automated and passing; policies reviewed by counsel; tax settings confirmed by adviser; live providers approved; rollback and payment-freeze rehearsed; runbooks and on-call ready.
