# UAT results — 04-deputy-director-general

- Date: 2026-10-07 19:01 (Africa/Lagos)
- Environment: https://localhost:19443 (UAT, synthetic data)
- Run by: scripts/uat/run.py (signed in as each tester, password and TOTP)

| # | Step | Result | Observed |
|---|---|---|---|
| A1 | National dashboard | ✓ Pass | national dashboard |
| A2 | Acknowledge the escalation | ✓ Pass | acknowledged by the DDG |
| B1 | Access oversight | ✓ Pass | the Lagos→Kano request and grant listed |
| B2 | Person search as a national user | ✓ Pass | normal result; no notice |
| C1 | Exports review | ✓ Pass | sd.lagos's export listed |
| D1 | Analytics | ✓ Pass | analytics open |
| D2 | No account management | ✓ Pass | refused (403) |
