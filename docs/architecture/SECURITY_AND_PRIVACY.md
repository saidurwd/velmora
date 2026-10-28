# Security and Privacy (Batch 6 draft)

Labels: [PINNED] · [RECOMMENDATION] · [NEEDS-LAWYER]. Not a compliance claim.

## 1. Trust boundaries and STRIDE
| Boundary | Threats (S/T/R/I/D/E) | Controls |
|---|---|---|
| Internet → web/API | S: credential stuffing, OTP abuse; T: tampered prices/params; D: floods; I: enumeration; E: IDOR | rate limits (API §2), generic auth responses, server-side recompute, scoped queries, WAF/CDN optional |
| Gateway/courier → webhook | S: forged callbacks; T: replay; R: disputes; D: storms | server-to-server verify, unique events, replay window, raw event store, queue |
| App → DB | T: SQL injection; E: over-privileged user | Eloquent/bound params, least-privilege DB roles (app / migrate / read-only reporting), append-only triggers |
| App → object storage | I: public leakage of digital goods; E: path traversal | private bucket, signed short-lived URLs, generated keys, no user-supplied paths |
| Admin → app | E: privilege escalation, session theft; R: repudiation | separate guard, mandatory TOTP MFA, RBAC deny-by-default, audit_events, session list, IP/time anomalies alert |
| App → third parties | I: PII to couriers/SMS; SSRF | minimal payloads, allow-listed outbound hosts, no user-controlled URL fetch |
| CI/CD & secrets | T: supply chain; I: leaked secrets | `composer audit`/`npm audit`, lockfiles, secret scanning, protected branches, signed deploys |

## 2. OWASP Top 10 mapping (concrete controls)
| Risk | Controls |
|---|---|
| A01 Broken access control | Policy on every route; route-enumeration test fails if a route lacks authorization; ownership scoping; `{id}` IDOR suite |
| A02 Cryptographic failures | TLS 1.2+ HSTS; argon2id (or bcrypt cost per ADR); Laravel `encrypted` casts for credentials/TOTP; backups encrypted |
| A03 Injection | bound params only; raw SQL banned by arch test except reviewed allow-list; HTML sanitiser allow-list for CMS/product descriptions; Blade escaping default |
| A04 Insecure design | threat model per feature slice, concurrency playbook, idempotency, state machines |
| A05 Misconfiguration | `APP_DEBUG=false` outside local, boot-time config validation, CSP (report-only → enforce), secure headers, trusted proxies |
| A06 Vulnerable components | dependency audit in CI, ADR per dependency, scheduled updates |
| A07 Auth failures | lockout/throttle, OTP hashing + expiry + attempt caps, MFA for staff, session fixation protection, re-auth for sensitive actions |
| A08 Integrity failures | signed webhooks/verify calls, verified build artefacts, outbox idempotency |
| A09 Logging/monitoring | JSON logs, request id, redaction processor, alerts on auth/payment anomalies |
| A10 SSRF | no user-controlled outbound URLs; image fetch disabled; outbound allow-list |

## 3. Authentication and sessions
Customers: email+password or phone+OTP. OTP: 6 digits, hashed at rest, 5-min expiry, ≤5 attempts, resend throttle, per-number/per-IP/global-daily caps, **SMS-pumping guard** (country allow-list, velocity, cost alert, CAPTCHA/proof-of-work fallback when abused) [NEEDS-HUMAN-DECISION on CAPTCHA vendor]. SMS provider SSL Wireless ISMS Plus: IP whitelisting means outbound SMS must leave from a fixed IP; use a transactional Sender ID for OTP/order messages; keep promotional SMS consent-based and within permitted hours [UNVERIFIED rules — confirm with BTRC/provider]. Passwords: min 10 chars, breach-list check optional; reset tokens hashed, single-use, 1 h. Sessions: HttpOnly, Secure, SameSite=Lax, rotation on login, idle + absolute timeout; "log out other devices". Staff: TOTP mandatory, recovery codes single-use, 30-min idle timeout, forced enrolment; last Super Admin protected; Super-Admin bypass explicit and audited.

## 4. Payments and fraud
No card data stored; provider secrets encrypted; success only after server-to-server verification (PAYMENT_INTEGRATION_DESIGN). COD abuse controls: blocklist, open-COD-order cap per phone, velocity, high-value → prepay/OTP confirmation, repeat-return score, `needs_review` queue. Logs mask gateway URLs containing credentials in query strings.

## 5. File handling
Uploads: allow-listed MIME + magic-byte check, size caps, image re-encode + EXIF strip, random storage keys, virus-scan hook, stored outside web root; attachments private. Downloads: authz → entitlement atomic increment → short-lived signed URL; every download logged; no permanent public URL for digital files.

## 6. PII inventory and retention
| Data | Where | Sensitivity | Retention / handling |
|---|---|---|---|
| name, phone, email, address | users, customers, addresses, order_addresses | PII | order snapshots kept with orders (≥ 6 y proposed); customer deletion = anonymise profile, keep financial rows |
| OTP, reset tokens | otp_challenges, tokens | secret | hashed; purge ≤ 7 d |
| IP, user-agent | audit_events, download_logs, sessions | PII-ish | 12 months then truncate [ASSUMPTION] |
| Payment refs, raw webhooks | payments, events | financial | raw payload nulled after 18 m |
| Search queries | search_queries | none (no user id) | aggregate after 90 d |
| Support attachments | private bucket | PII | per ticket policy |
Consent captured and versioned (`consents`, policy acceptances); marketing opt-in separate. Data export/deletion workflows (Phase 9) admin-approved. Applicable law: PDPO 2025 status conflicting in sources [NEEDS-LAWYER]; Digital Commerce Guidelines consent/retention [NEEDS-LAWYER].

## 7. Logging and redaction
Never log passwords, OTPs, tokens, full phone numbers, full payment payloads, `store_passwd`-style query strings. Processor masks phone (+88017****1234), emails, card-like numbers, known secret keys. Redaction test in CI.

## 8. Backup, restore, incident response
Encrypted DB backups (full + binlog for PITR), object-storage versioning, monthly restore rehearsal (RTO ≤ 4 h, RPO ≤ 15 min proposed). Incident runbook: detect → triage severity → contain (payment-freeze, token revoke, key rotation) → communicate → postmortem; breach notification duties [NEEDS-LAWYER].

## 9. Security checklists
**Development:** FormRequest on every input; Policy on every route; no raw SQL; no secrets in code; tests for authz/IDOR; dependencies justified; PII not logged; both locales tested.
**Staging:** prod-like config; debug off; test credentials only; CSP report-only reviewed; scanners run (ZAP baseline); webhook IPs/URLs configured to sandbox.
**Production:** HTTPS+HSTS; CSP enforced; MFA on all staff and infra accounts; DB roles least-privilege; secrets in manager, rotated; backups + restore proven; alerts routed; admin IP/anomaly alerts; WAF/rate limits on; pen-test findings closed (Critical/High = 0); live-payment smoke test human-executed.
