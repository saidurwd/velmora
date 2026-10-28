# Phase 2 — Identity, Access Control & Localisation

> **Project overrides (read first):** database is **MariaDB**, not PostgreSQL (`docs/architecture/DATABASE_ENGINE_DECISION.md`, ADR-000); default language is English; payments SSLCommerz(+bKash)+COD; tax is configurable (ADR-030); stock commits at shipment (ADR-013). Where this prompt says PostgreSQL/tsvector/EXCLUDE/timestamptz, use the ADR-000 replacement table.

| | |
|---|---|
| **Effort** | 10–15 person-days |
| **Prerequisites** | Phase 1 |
| **Modules** | IdentityAccess, Customers (profile only), Cms (languages), Notifications (OTP/email transport only), Platform |
| **Attach** | `SECURITY_AND_PRIVACY.md`, `BANGLADESH_LOCALIZATION_AND_COMMERCE.md`, `WEB_UI_UX_GUIDELINES.md` |
| **Exit gate** | Customer + staff can authenticate securely in en/bn; RBAC enforced; language switching proven by tests |

## Copy-paste prompt

```text
You are the implementation agent for this project. Before doing anything:
1. Read AGENTS.md, docs/architecture/ARCHITECTURE_DECISION_RECORDS.md, DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md, DATABASE_DESIGN.md, the docs listed under "Attach", and the previous phase's report/CHANGELOG.
2. Inspect the current repository state (git status, existing modules, migrations, tests). Do not overwrite or delete anything you do not understand.
3. Reply FIRST with a short plan: files/tables/events/tests you will add, risks, and any questions. For anything touching schema, money, stock, or authentication, WAIT for my approval before editing.
4. Then work in small vertical slices (migration + model + Action + policy + validation + controller/resource/view + en/bn strings + tests), running tests after each slice.
5. Never weaken tests or security to get green. Never claim a test passed unless you ran it.

OBJECTIVE
Secure authentication and authorization for customers, guests and staff, plus complete English/Bangla infrastructure used by every later module.

TASKS
1. **Accounts:** `users` (email nullable-unique, phone E.164 nullable-unique, password nullable for OTP-only accounts, `account_type` customer|staff, locale, status, last_login_at, email_verified_at, phone_verified_at). `customers` profile table (name, birth date optional, marketing consent with timestamp). Decision recorded in an ADR if you choose differently.
2. **Customer auth (web):** register/login with email+password **or** BD phone + OTP; password reset; email/phone verification; remember-me; logout other devices; account lockout/throttling; session fixation protection.
3. **OTP service:** `otp_challenges` (hashed code, purpose, target, expires_at 5 min, attempts, consumed_at); `SmsGateway` contract + `LogSmsGateway` (dev) + `FakeSmsGateway` (tests); per-number/per-IP/per-day limits; generic responses (no account enumeration); cost-abuse (SMS pumping) guard: per-IP and global daily cap with alerting hook.
4. **Staff auth:** separate login route/prefix, guard `admin`, mandatory TOTP MFA with recovery codes, session timeout, device/session list, forced MFA enrolment on first login.
5. **RBAC:** roles & permissions (`<resource>.<action>`), seeded roles (Super Admin, Admin, Catalog Manager, Order Manager, Warehouse, Finance, Support, Content Editor, Read-only), permission-gate middleware, Policy base class, permission matrix doc, admin UI to manage roles & staff. Super-admin bypass must be explicit and audited. Last Super Admin cannot be removed/demoted.
6. **Audit:** log logins, failures, MFA events, role/permission changes, staff create/disable (use Platform AuditLogger).
7. **Localisation:** `languages` seeded (en default, bn); `SetLocale` middleware (URL prefix → user → cookie → Accept-Language → default); locale-prefixed route macro; language switcher component preserving the current page; `hreflang`/canonical helpers; locale formatter facade (date, number, currency, relative time) with Bangla-digit display option; translated validation messages; translated auth emails/SMS; Bangla font stack verified; `lang/{en,bn}` structure by module.
8. **Account area (minimal):** profile edit, change password, change phone/email with re-verification, language preference, delete-account request (stub for Phase 9).
9. **Admin shell:** sidebar, permission-aware menu, empty dashboard.

DATABASE: users, customers, otp_challenges, password_reset_tokens, personal_access_tokens (Sanctum), roles, permissions, role_has_permissions, model_has_roles, staff_mfa_credentials, user_sessions (if device list), + indexes on lower(email), phone.
API: none public yet; but Actions must be callable from future API (no session coupling).
SECURITY: argon2id/bcrypt cost per ADR; constant-time compare; no user enumeration; CSRF on all state-changing web routes; secure cookie flags; login & OTP rate limits; brute-force tests.

TESTS (must pass)
- Registration/login by email and by phone+OTP, including Bangla-digit OTP and phone input.
- OTP expiry, reuse, wrong-code lockout, resend throttle, enumeration-safe responses.
- MFA required for staff; recovery code single-use; cannot reach admin routes with customer account.
- Every admin route has a permission test (data-driven test enumerating routes → fails if a route lacks authorization).
- Policy tests: customer A cannot read/update customer B.
- Locale: all routes available in /en and /bn; fallback; switcher keeps path/query; parity test of lang keys; HTML `lang` attribute; emails render Bangla correctly.
- Last Super Admin protection; audit events written.

ACCEPTANCE CRITERIA
- No route is reachable without explicit authorization decision (deny-by-default test).
- An attacker cannot determine whether an email/phone is registered from any response or timing within tolerance.
- Switching language never loses the user's place and persists across sessions.
- Security checklist items for authN/authZ in SECURITY_AND_PRIVACY.md all ticked with evidence.

REPORT BACK USING THIS FORMAT
1) Summary  2) Files changed  3) Commands run + real results  4) Acceptance-criteria checklist with evidence (test names / screenshots)  5) Deviations from docs (and ADRs written)  6) Known gaps / tech debt  7) Questions & decisions I must make
```
