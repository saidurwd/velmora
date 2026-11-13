# A security report or penetration-test finding arrives

1. **Thank and acknowledge** the reporter within one working day. Do not argue; do not publish.
2. **Reproduce** in a throw-away environment, never on production data. Add the finding to `docs/release/SECURITY_FINDINGS_REGISTER.md` (ID, who found it, how, severity, status).
3. **Severity** (proposal): *Critical/High* = money or personal data can be taken or changed now → pause the affected feature, fix within days; *Medium* = weakens a defence or needs unusual conditions → fix in the next release; *Low/Info* = hardening → schedule. The register's rule for launch: no open Critical or High; every Medium fixed or accepted by the owner in writing with a date.
4. **Fix with a test that fails without the fix** (a regression test), never by weakening a check. Run the full suite and the browser tests.
5. **Tell the reporter** when it is fixed. Record the release in the register.
6. If it was exploited, follow [secret-leak.md](secret-leak.md) for what was exposed.
