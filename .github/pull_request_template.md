## What and why
<!-- Link the backlog item (E<phase>-<n>) and docs/ADRs. -->

## Risk
- [ ] Touches money, stock, auth or schema (plan was approved: ___)

## Definition of Done (docs/architecture/DEFINITION_OF_DONE.md)
- [ ] Acceptance criteria met, evidence below
- [ ] Tests first for risky logic; concurrency/replay/IDOR tests where relevant
- [ ] `composer check` passes (Pint, Larastan 8, architecture, tests)
- [ ] en **and** bn strings added; parity test green; viewed in both locales
- [ ] Authorization verified (every route has a policy/permission); ownership checks for customer data
- [ ] No N+1 (query-count assertion on lists); new queries EXPLAINed; indexes justified in the migration
- [ ] Migration is single-purpose, forward-only, expand → migrate → contract
- [ ] Audit events for admin / financial / stock changes; logs redacted; no secrets
- [ ] Docs updated: data dictionary, endpoint catalog/OpenAPI, ADR, CHANGELOG

## Evidence
<!-- commands run + real results, screenshots -->

## Deviations / follow-ups
