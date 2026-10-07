# UAT results — 05-director-general

- Date: 2026-10-07 19:01 (Africa/Lagos)
- Environment: https://localhost:19443 (UAT, synthetic data)
- Run by: scripts/uat/run.py (signed in as each tester, password and TOTP)

| # | Step | Result | Observed |
|---|---|---|---|
| A1 | Dashboard and notifications | ✓ Pass | national dashboard; 413 unread notifications |
| B1 | Reference data: add and deactivate a threat type | ✓ Pass | added, offered in the form, deactivated again |
| B2 | Audit log by user | ✓ Pass | sd.lagos's closure, export and delegation in the audit log |
| C1 | Invalidate a duplicate | ✓ Pass | Invalid (duplicate), locked |
| D1 | Quick reference card | ✓ Pass | one-page PDF (111281 bytes) |
