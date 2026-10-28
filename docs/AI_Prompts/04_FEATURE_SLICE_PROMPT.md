# 04 — Feature-Slice Prompt (repeatable, one feature at a time)

Use this for any single feature, bug fix or backlog item — inside a phase or after launch. It keeps the AI small-scoped, test-first, and honest. Fill the `{{…}}` fields from `docs/backlog/BACKLOG.md`. Attach `AGENTS.md` (or let the agent read it from the repo).

```text
TASK {{ID}} — {{title}}
Module(s): {{Module}}          Phase: {{N}}          Risk level: {{low | money/stock/auth = HIGH}}

CONTEXT
{{2–5 lines: user story, why it matters, links to docs/architecture sections and ADRs}}

ACCEPTANCE CRITERIA (testable)
1. {{Given/When/Then}}
2. …
OUT OF SCOPE
- {{explicitly list neighbouring things NOT to touch}}

PROCESS (follow in order)
1. Read AGENTS.md, the cited docs/ADRs, and the existing code/tests in the module. Summarise what already exists that you will reuse.
2. Post a plan: files to create/change, migration(s), Action(s), events, policies/permissions, translations (en+bn), tests to write, query/index impact, security notes, risks, open questions.
   - If HIGH risk: STOP and wait for my approval of the plan.
3. Write the failing tests first for the acceptance criteria (and for concurrency/security cases listed in the phase prompt where relevant).
4. Implement the smallest vertical slice that makes them pass (migration → model → Action → policy → request → controller/resource/view → lang files).
5. Run: formatter, static analysis, architecture tests, the module's tests, then the full suite. Fix root causes; never skip/weaken tests.
6. Check: N+1 (query-count assertion), indexes for new queries (EXPLAIN on realistic data), authorization on every new route, bn+en rendering, redaction of sensitive logs.
7. Update docs: data dictionary, endpoint catalog/OpenAPI, ADR (if a decision changed), CHANGELOG.
8. Commit in small Conventional-Commit steps.

REPORT BACK
Summary · files changed · commands run with real output summary · acceptance-criteria checklist with evidence · deviations/ADRs · known gaps · questions for me.
```

## Variants

**Bug fix:** replace steps 3–4 with "Reproduce with a failing regression test, find the root cause (not the symptom), fix, prove the test now passes, and add a guard against the class of bug."

**Spike / research:** "No production code. Produce a 1–2 page note: options, evidence (cited), recommendation, cost, risks, reversal plan. Save under `docs/spikes/`."

**Review-only (second-AI audit):** "Do not modify files. Review diff {{range}} against AGENTS.md and docs. Report findings by severity with file:line, failure scenario, and suggested fix. Check specifically: authorization gaps, race conditions, money rounding, missing translations, N+1, secrets/PII in logs, migration safety."
