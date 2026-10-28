# Contributing

1. Pick a backlog item (`docs/backlog/BACKLOG.md`); read `AGENTS.md` and the cited docs/ADRs first.
2. **Plan before code.** For anything touching money, stock, auth or schema, get the plan approved.
3. Work in small vertical slices: migration → model → Action → policy → request → controller/resource/view → en + bn strings → tests.
4. Write the failing test first for risky logic (payments, stock, coupons, refunds, bookings, authorization).
5. Run `composer check` before pushing; CI runs the same plus an audit and secret scan.
6. Conventional Commits (`feat(orders): …`, `fix(payments): …`, `docs: …`). One concern per PR; fill in the PR template.
7. Record decisions: new or changed architecture decisions need an ADR (`docs/architecture/adr-template.md`) and owner approval.

## Rules that fail the build
Module boundary violations · missing translation keys (en/bn parity) · hard-coded user-visible text in Blade · float/decimal money columns ·
`dd()/dump()/ray()` · missing `declare(strict_types=1)` · Pint style · Larastan level 8.

## Never
Commit secrets or `.env` · run tests against a non-`*_test` database · disable or skip tests/validation/authorization to get green ·
call live payment/courier/SMS services from tests · add a dependency without a written reason.
