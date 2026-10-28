# Phase 9 — Customer Account & Engagement

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | 12–18 person-days |
| **Prerequisites** | Phases 6–8 |
| **Modules** | Customers, Notifications, Reviews/Engagement, Support, Orders (customer views) |
| **Attach** | `WEB_UI_UX_GUIDELINES.md`, `BANGLADESH_LOCALIZATION_AND_COMMERCE.md` |
| **Exit gate** | Customers can self-serve orders, returns, reviews and support in both languages; notification delivery is reliable and observable |

## Copy-paste prompt

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

OBJECTIVE
Retention-oriented, self-service customer experience and reliable bilingual communications.

TASKS
1. **Account area:** order history/detail/tracking, reorder, invoices (PDF), address book, digital library, my bookings, wishlist, saved preferences, notification preferences, privacy tools (data export request, deletion request → admin-approved workflow respecting legal retention).
2. **Notifications platform:** channel contracts (`Email`, `Sms`, `WhatsApp?`, `Push` stub), template system with per-locale templates (admin-editable with variables, preview, version history), event→template mapping, user locale awareness, quiet hours, opt-outs for marketing (not for transactional), delivery log (`notification_logs`) with provider status callbacks, retry with backoff, dead-letter view, cost counters per channel (SMS is a real cost).
3. **Transactional set (bn+en):** order placed, confirmed, payment received/failed, shipped, out for delivery, delivered, failed delivery, return/refund updates, digital ready, booking confirmed/reminder/changed, OTP, password reset, low-stock (staff).
4. **Reviews & ratings:** verified-purchase only, one per order item, moderation queue, report abuse, helpful votes, aggregate rating maintained by event (not live avg), bilingual display (show in written language), spam rules, Q&A on products (optional).
5. **Wishlist** (+ back-in-stock / price-drop subscriptions, optional), **product comparison** only if discovery justified it.
6. **Returns / cancellation / refund requests (customer side):** eligibility by policy and order state, reason codes, photos, status timeline; staff workflow hooks into Phase 6/7.
7. **Support:** contact form + tickets (`support_tickets`, messages, attachments, status, assignee, SLA timers), order-linked; canned replies (bn/en); click-to-call / WhatsApp / Messenger deep links as configured channels; FAQ/help-center search.
8. **Abandoned cart recovery** (opt-in, rate-limited, SMS/email) — only with consent.

SECURITY/PRIVACY: ownership checks everywhere; consent records; data export contains only the requester's data; deletion = anonymisation preserving financial records; upload hardening; no PII in notification logs beyond what's necessary.

TESTS (must pass)
- Every customer page returns 404/403 for another customer's resources (data-driven).
- Notification: locale selection, template variable injection safe from XSS/format injection, retry/dead-letter, opt-out respected for marketing but not for transactional.
- Review eligibility (only delivered/consumed items), duplicate review blocked, aggregate stays consistent under concurrent submissions.
- Return eligibility windows; cannot return digital goods against policy.
- Data export/deletion workflow leaves ledger intact.

ACCEPTANCE CRITERIA
- A customer can see where their order/refund/booking is without contacting support.
- Notification failures are visible to staff, not silent.

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
