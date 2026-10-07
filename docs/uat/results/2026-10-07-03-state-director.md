# UAT results — 03-state-director

- Date: 2026-10-07 19:01 (Africa/Lagos)
- Environment: https://localhost:19443 (UAT, synthetic data)
- Run by: scripts/uat/run.py (signed in as each tester, password and TOTP)

| # | Step | Result | Observed |
|---|---|---|---|
| A1 | Resolve the robbery | ✓ Pass | Resolved |
| A2 | Close: locked | ✓ Pass | Closed and locked (offered now: nothing) |
| A3 | Print | ✓ Pass | print view with the classification and "Printed by UAT State Director Lagos" |
| B1 | Disseminate the threat to Kano | ✓ Pass | shared with Kano |
| B2 | Kano: shared threat; acknowledge | ✓ Pass | listed read-only; acknowledged; no History |
| C1 | Kano: request awaiting decision | ✓ Pass | request from UAT LGA Chief Lagos |
| C2 | Kano: approve (narrowed to profile), step-up | ✓ Pass | approved for 7 days, profile only (authenticator code asked) |
| C3 | Chief: view under the grant | ✓ Pass | profile visible read-only; no NIN, phone or LGA |
| D1 | Delegate approvals to the Deputy | ✓ Pass | active; the Deputy now decides requests |
| D2 | Revoke the delegation | ✓ Pass | revoked; the Deputy can no longer decide |
| E1 | Incident list export (XLSX) | ✓ Pass | queued and ready; authenticator code not asked |
| E2 | Export carries markings, no descriptions | ✓ Pass | marked SECRET (highest level included) and "Generated for"; no descriptions; authenticator code asked for the secret download (110572 bytes) |
| E3 | Suggested blackspots | ✓ Pass | suggestions page opens |
