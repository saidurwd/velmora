# Risk Register (final for Batch 7; living document)

P/I = probability / impact (L/M/H). **Owner: product owner (Saidur Rahman) unless noted; status: Open unless noted.** Review at every phase exit gate.

| # | Risk | P | I | Mitigation | Trigger |
|---|---|---|---|---|---|
| 1 | Gateway merchant onboarding delays | H | H | Apply Day 1 (2 aggregators); build against fake gateway | No sandbox by Phase 5 |
| 2 | Courier API access delayed / docs incomplete | M | H | ManualCourier fallback | No creds by Phase 7 |
| 3 | SMS sender-ID (BTRC / SSL Wireless) approval lead time; server IP must be whitelisted | H | M | Apply now for transactional Sender ID; static egress IP; email fallback | No sender-ID by Phase 2 |
| 4 | Provider capability assumed wrongly (refunds, webhooks) | M | H | Verify from official docs, `capabilities()` flags | Doc gap found |
| 5 | Legal/tax rules change or misread (VAT, PDPO) | M | H | Lawyer/tax-adviser review; configurable policies | New ordinance/guideline |
| 6 | High COD return rate / fake orders | H | H | Fraud rules, confirmation calls, prepay thresholds | Return rate > target |
| 7 | Overselling | L | H | Atomic reservation + DB CHECK + concurrency tests | Any incident |
| 8 | Double/lost payment | M | H | Idempotent callbacks, outbox, reconciliation | Reconciliation exceptions |
| 9 | SMS cost / pumping abuse | M | M | Rate limits, daily caps, alerts | Spend spike |
| 10 | Poor Bangla search quality | M | M | Normalisation + trigram + synonyms; fixtures | Zero-result rate high |
| 11 | Bangla rendering on low-end Android | M | M | Self-hosted subset font, device testing | Visual defects |
| 12 | Single-developer bus factor | H | H | ADRs, docs, AGENTS.md, CI | Dev unavailable |
| 13 | Scope creep (marketplace, subscriptions) | M | M | MVP gate, "do not build yet" list | New epic requests |
| 14 | Schedule overrun (180–270 days) | H | M | Phase gates, cut Phase 8/9 extras | Phase > 130 % estimate |
| 15 | Schema mistakes found late | M | H | Batch 3 deep review, expand/contract | Migration rework |
| 16 | Data residency / hosting latency | M | M | Decide region early (Q7) | Pre-launch audit |
| 17 | AI agent drift from architecture | M | M | Pinned decisions, arch tests, review sessions | Arch test failures |
| 18 | PII leak in logs/exports | L | H | Redaction, masked lists, export audit | Audit finding |
| 19 | Payment redirect forgery | L | H | Server-to-server verification only | Pen-test finding |
| 20 | Courier status mapping errors | M | M | Mapping table, unknown-status alert | Unknown status events |
| 21 | Backup not restorable | L | H | Monthly restore drill | Failed drill |
| 22 | Performance target miss on 3G | M | M | Budgets in CI from Phase 4 | LCP > 2.5 s |
| 23 | Third-party package abandonment | L | M | ADR per dependency, audit | Security advisory |
| 24 | Refund/dispute handling gaps | M | M | Refund guard, approval workflow | Dispute volume |
| 25 | Free/aggregated geography data inaccurate | M | L | Authoritative source + date, admin edit | Customer address errors |
| 26 | Booking constraint is MariaDB-only (WITHOUT OVERLAPS); group-capacity sessions not covered | L | M | S1 verified; keep slot-grid fallback; capacity>1 design in Phase 8 | Move to MySQL / group sessions |
| 27 | Weak Bangla search (no infix/typo) with FULLTEXT | H | M | Fixtures, synonyms, adopt Meilisearch/Typesense on trigger | Zero-result rate/fixtures |
| 28 | Non-transactional DDL leaves half-applied migrations | M | M | Small migrations, expand/contract, staging rehearsal | Failed deploy |
| 29 | Gap-lock deadlocks under REPEATABLE READ at checkout | M | M | READ COMMITTED + ordered locks + bounded retry + load test | Deadlock rate |
| 30 | Weak payment adapter assumptions: SSLCommerz `verify_sign` method undocumented, partial refund unstated, live refund needs IP registration | M | H | authenticate via Validation API; confirm partial refund with SSLCommerz in writing; register IPs | Sandbox/live behaviour differs |
| 31 | bKash/Nagad/ShurjoPay/courier official docs unavailable to us | H | M | owner supplies merchant-portal docs into `docs/vendor/`; never guess endpoints | Phase 6/7 start |
| 32 | Tax rules misconfigured after VAT registration (rates, inclusive/exclusive, invoice layout) | M | H | tax stays off until adviser confirms; dated rates; reconciliation tests | Registration date |
| 33 | SMS provider IP whitelist breaks on infrastructure change | L | M | static egress IP, runbook | SMS failures after deploy |
| 34 | Documents (dictionary) not column-complete → schema surprises | M | M | generate dictionary from migrations, CI check vs information_schema | Phase 1–3 |
