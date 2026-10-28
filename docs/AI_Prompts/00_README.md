# AI Prompt Pack — Bangladesh-Ready Laravel + Blade + PostgreSQL E-commerce

Built from your `Draft.md`. Your draft is a strong requirements brief; this pack turns it into a **repeatable AI-driven delivery system** by (1) pinning the irreversible architecture decisions so AI sessions can't drift, (2) splitting the work into stop-and-review batches, and (3) giving every development phase its own ready-to-paste, testable prompt.

## Files

| File | Purpose | When |
|---|---|---|
| `01_MASTER_ARCHITECTURE_PROMPT.md` | Produces the full `docs/architecture/` package (24+ docs, diagrams, risk register, backlog) in 7 reviewed batches. **No code.** | First |
| `02_BASELINE_DECISIONS.md` | Pinned decisions & concurrency/payment/inventory patterns, Bangladesh specifics, module map, targets, MVP classes, compliance *candidates to verify*. | Attach to **every** prompt |
| `03_AGENTS.md` | Repo-level rules for AI coding agents (copy to repo root as `AGENTS.md`). | Phase 1 onward |
| `04_FEATURE_SLICE_PROMPT.md` | Repeatable template for one feature / bug / spike / review. | Daily work |
| `phases/PHASE_00 … PHASE_14` | One copy-paste prompt per phase: scope, tasks, DB, security, tests, acceptance criteria, report format. | In order |

## Workflow

1. Create the repo. Put `Draft.md`, `02_BASELINE_DECISIONS.md` in `docs/inputs/`.
2. Run **Phase 0** owner checklist now (gateway/courier/SMS onboarding has the longest lead time).
3. Run `01_MASTER_ARCHITECTURE_PROMPT.md` batch by batch; review at each STOP — **Batch 3 (database) is the most valuable review**.
4. Run **Phase 1** scaffold; copy `03_AGENTS.md` → `AGENTS.md` with real commands.
5. For each later phase: paste the phase prompt → approve the AI's plan → review slices → require the report → tick acceptance criteria → merge → next phase.
6. After launch loop **Phase 14** every two weeks.

Tips: new session per phase (the repo + docs are the memory, not the chat); keep `docs/` and ADRs updated by the AI in the same PR; use a *different* AI session (or model) for review-only passes on money/stock/auth code.

## Phase map and estimates (1 senior developer + AI agents; planning ranges, not promises)

| Phase | Name | Days |
|---|---|---|
| 0 | Discovery & feasibility | 5–8 |
| 1 | Architecture approval & foundation | 14–22 |
| 2 | Identity, access, localisation | 10–15 |
| 3 | Catalog, PIM & inventory core | 15–22 |
| 4 | Storefront & search | 12–18 |
| 5 | Cart, checkout & order core | 15–22 |
| 6 | Payments, COD, refunds, reconciliation | 20–30 |
| 7 | Fulfilment, couriers, returns | 15–22 |
| 8 | Digital products & service bookings | 15–22 |
| 9 | Customer account & engagement | 12–18 |
| 10 | Admin, reports, CMS | 18–25 |
| 11 | Mobile API v1 | 12–18 |
| 12 | Hardening & pre-production | 12–18 |
| 13 | Production launch | 6–10 |
| 14 | Post-launch cycles | ongoing |
| | **Total to launch** | **≈ 180–270 person-days** |

Critical path: 1 → 2 → 3 → 5 → 6 → 7 → 12 → 13. Phases 4 and 8 can overlap with 5–6 if you add people; Phase 11 can start once 5–9 stabilise. Provider onboarding (gateway, courier, SMS sender-ID) is the main external schedule risk.

## What I changed from Draft.md's phase order, and why

1. **Inventory core moved from Phase 7 → Phase 3.** Checkout (Phase 5) must reserve stock atomically; building cart/checkout first would mean rewriting it. Phase 7 keeps warehouse operations, couriers and returns.
2. **Order aggregate + state machines created in Phase 5** (checkout has to create an order); Phase 6 attaches payments to it.
3. **Phase 1 split** into architecture approval (master prompt) and scaffold (first code), with a Platform shared kernel (Money, outbox, idempotency, audit, phone/Bangla utilities) so later phases don't reinvent them.
4. **Phase 4 includes SEO, performance budgets and accessibility checks** instead of leaving them for Phase 12, where they are expensive to retrofit.
5. **Phase 0 gets an owner checklist** because non-technical lead times (licences, gateway approval, SMS sender-ID) dominate the schedule.

## What I added beyond the draft

* Pinned decisions: money as integer minor units, normalised translation tables, three-axis order status, outbox + idempotency, `tstzrange` exclusion constraint for bookings, atomic conditional-UPDATE stock reservation, signed private-bucket digital delivery.
* Bangladesh-specific controls: phone-first OTP identity with SMS-pumping defences, COD abuse/fraud rules, COD remittance reconciliation, Bangla search normalisation and digit handling, Mushak-style VAT invoice as a *verify* item.
* Seven extra high-risk test scenarios (callback-before-redirect, late callback after expiry, amount mismatch, out-of-order courier events, OTP brute force, Bangla-digit input, missing-translation fallback).
* Measurable performance/availability targets (marked as proposals to confirm).
* Honest-reporting and "ask a human before" rules for AI agents, plus a standard report format.

## Things only you can decide (the AI will ask; here is the short list)

Single vendor vs marketplace · default storefront language · which gateway/courier/SMS provider first · VAT registration & invoicing model · hosting location · COD policy (fees, limits, confirmation calls) · return/refund policy · team size and launch date · whether purchasing/suppliers are in MVP.

> Legal and tax items in this pack are **candidates to verify**, not legal advice. Have a qualified Bangladeshi lawyer/tax adviser confirm them.
