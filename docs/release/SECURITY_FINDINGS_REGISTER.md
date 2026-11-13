# Security findings register — Phase 12 review

Reviewer: Claude (as the sceptical reviewer the Phase 12 brief asks for), working from the code, the automated tests and tools named below. **This is not a penetration test and not an independent audit.** It covers what can be checked from the repository and a throw-away MariaDB 11.4; it does not cover production infrastructure, DNS/TLS, the hosting provider, or a human attacker's creativity. An external penetration test (Phase 12 task 2) is still needed before launch and is the owner's to arrange.

Severity: **Critical/High** = exploitable now for money or personal data; **Medium** = weakens a defence or exposes data under conditions; **Low** = hardening or minor. Status: Fixed (with the test that proves it) · Accepted (owner/reviewer decision, with reason) · Open.

## What was checked, and how

| Area | Method | Result |
|---|---|---|
| Dependencies | `composer audit`, `npm audit --omit=dev` | no advisories |
| Secrets in the repository | pattern scan of every tracked file (cloud keys, private keys, tokens) + list of tracked env/key files | none; `.env.example` only; the e2e seed holds a throw-away two-step secret, documented as such |
| SQL injection | every raw-SQL call site read; all user-influenced values are bound parameters; identifiers come from constants | none found |
| Mass assignment | search for unguarded models and `fill/create/update(request->all())` | none |
| Stored/reflected XSS | every `{!! !!}` read; staff rich text goes through HTMLPurifier (allow-list, no styles/handlers/iframes, only http/https/mailto/tel links) at write time; everything else is escaped | see F-4 |
| CSRF | CSRF on for all web routes; one exemption (`*/pay/return/*`, the gateway's browser return) that believes nothing it carries and verifies server-to-server | ok |
| Open redirect / SSRF | the only outbound redirect is to the payment gateway's page, checked to be `https` on `sslcommerz.com`; outbound HTTP only to fixed providers from server settings | ok |
| File uploads | images are decoded and re-encoded (drops hidden data), PDFs sniffed, CSV size/extension limited, private disk, random names, malware-scan hook | ok |
| CSV/spreadsheet injection | every export goes through one writer that neutralises `= + - @` cells | ok |
| Search syntax injection | query reduced to letters, digits and marks before it reaches the full-text engine | ok |
| Authentication | lockout after 10 failures, per-minute throttle, equal timing for unknown accounts, strong-password rule, staff two-step verification, single-use refresh tokens with theft detection | ok |
| Authorization | deny-by-default route test (web, admin, API); ownership checks proved by cross-customer tests per module; API returns 404 not 403 for others' objects | ok |
| Logging | every `Log::` call that mentions a phone/token/OTP/payload read | masked or absent; the development-only log transports refuse production |
| Rate limits | architecture tests: every `/api/v1` route throttled, signed-in routes limited per customer | F-6 (fixed). Web admin writes: F-7 |
| Headers/cookies | HSTS (secure requests), nosniff, frame, referrer, permissions, COOP; session cookie secure/httpOnly/lax; production refuses to boot with debug on or insecure cookies | CSP: F-1, F-2, F-3 |

## Findings

| ID | Severity | Finding | Status |
|---|---|---|---|
| F-1 | Medium | The Content-Security-Policy was **report-only**, so it blocked nothing. And `.env.example`, which new servers are built from, said `CSP_ENFORCE=false`, so even the new default would have been switched off in production (CI caught it). | **Fixed**: enforced by default (`CSP_ENFORCE=true`, also in `.env.example`, guarded by a test); `form-action` allows the payment gateway's hosts (browsers check the redirect after the checkout form); `object-src 'none'` added. Proved by `tests/Architecture/ContentSecurityPolicyTest.php`, `SecurityHardeningTest`, and `e2e/csp.spec.js` (real browser: no violations on the storefront pages, the policy is sent). |
| F-2 | Medium (would have been a regression) | 17 views used **inline event handlers** and one an **inline script**, including the "are you sure?" question before deleting. Enforcing the policy would have silently removed those questions (a delete would go through without asking). | **Fixed**: replaced by `data-confirm`, `data-autosubmit`, `data-bulk-*`, `data-goto-*` handled by `resources/js/behaviors.js`. An architecture test now fails the build if any view brings an inline handler or inline script back. `e2e/csp.spec.js` proves the delete question is still asked and that "Cancel" really cancels (both mutants of the script were caught). |
| F-3 | Medium | `script-src` had `'unsafe-eval'` because Alpine's standard build evaluates `x-data` / `x-show` / `@click` expressions with `new Function`. | **Fixed**: Alpine runs as its CSP build (decision P12-1, with the written reason for the dependency); `'unsafe-eval'` removed. Proved by the header test, the architecture test (`ContentSecurityPolicyTest`) and browser tests that watch for Alpine errors and policy violations on the storefront, the component library (dialog), the staff coupon / promotion forms, the translation tabs and the print button. The first run caught one real break (`recentlyViewed` used `JSON.parse` in an expression), now fixed. |
| F-4 | Low | A category/brand/collection description was cut to 600 characters with a plain string cut **after** it was sanitised, which can leave a half-open tag and let the rest of the page be swallowed (a layout bug, not a script risk). | **Fixed**: the description is turned into plain text and cut at a word before it is shown (it also fed the page's meta description with tags in it). `StorefrontListingTest` "shows a category description as plain text…" fails on the old view, passes on the new. |
| F-5 | Low | The staff area was not usable at a phone's width: the sidebar took about 40 % of the screen and the page ended up wider than the screen (a table button could be unreachable). Separately, three tables used `display:flex` on a table cell. | **Fixed**: the sidebar is now a grouped, collapsible menu that stacks above the page on phones (no sideways overflow, checked at 360 px on the categories screen; other screens not individually checked), and the three table cells are fixed with `tests/Architecture/ViewMarkupTest.php` guarding the pattern. |
| F-6 | Medium | Rate limits for support tickets/replies, privacy requests and digital downloads read the website login, which the app never has, so the app was limited **per network address** instead of per customer (a carrier address shared by many phones; a person changing address escaped it). | **Fixed in slice 8** (`tests/Architecture/ApiThrottleTest.php` fails if it comes back). |
| F-7 | Low | 59 staff **write** routes (catalogue, CMS, staff, roles, pricing, shipping zones…) have no rate limit. They need a signed-in staff member with the right permission, so abuse needs a compromised or hostile account. | **Fixed**: one `admin-write` limit (120 changes a minute per staff member, reads not counted) on the whole admin route group. `tests/Architecture/ApiThrottleTest.php` fails if any staff write route lacks it; `AdminWriteLimitTest` drives 121 real writes and sees the 429 with `Retry-After`, and that another staff member is unaffected. Removing the limit makes both fail. |
| F-8 | Info | Horizon (queue dashboard) is allowed only when `APP_ENV=local`; in production nobody can open it. | Accepted; change deliberately if the owner wants it. |
| F-9 | Info | Sign-in is limited per (account, address) and an account locks for 15 minutes after 10 failures, which lets someone lock another person out of their account for 15 minutes at a time. This is the usual trade-off against password guessing. | Accepted. |
| F-11 | Low | The browser tests all sign in from one address and the sign-in limit (10 a minute) made them fail by chance. | **Fixed** without weakening production: `AUTH_THROTTLE_PER_MINUTE` (default 10) is raised only by `e2e/prepare.sh`; in production it is capped at 10 whatever the setting says (tested). |
| F-12 | **High** (production availability) | **`TRUSTED_PROXIES` did nothing from `.env` and `*` was not a wildcard.** `bootstrap/app.php` read the setting before Laravel had loaded `.env` for a web request, and turned `*` into a list holding a literal `*`, which Laravel does not read as "trust all". Behind a load balancer every visitor would look like the balancer: one shared rate-limit bucket for the whole shop (10 sign-ins a minute in total, 120 public reads a minute in total) and wrong addresses in every log and audit line. Found by the load test (every request got 429). | **Fixed**: `config/trustedproxy.php` (read after `.env`), a tested parser, an architecture test that forbids `env()` in `bootstrap/app.php`, and a real-server check that an address from `X-Forwarded-For` is seen. |
| F-10 | Info | Courier webhooks have only the fake courier; the real adapters (Steadfast, Pathao) do not exist yet, so their signature checks are not reviewed. SSLCommerz notifications are never trusted (the server asks the gateway). | To review when the adapters exist. |

## Not covered (needs other people or a real environment)

* Penetration test by a third party; ASVS L2 line-by-line mapping beyond the areas above.
* Production infrastructure: TLS configuration, firewall, SSH, backups encryption, secrets manager, WAF.
* The real payment gateway and courier behaviour.
* Load/abuse behaviour of rate limits under real traffic (limits are tested for correctness, not volume).
