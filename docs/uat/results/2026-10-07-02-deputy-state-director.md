# UAT results — 02-deputy-state-director

- Date: 2026-10-07 19:01 (Africa/Lagos)
- Environment: https://localhost:19443 (UAT, synthetic data)
- Run by: scripts/uat/run.py (signed in as each tester, password and TOTP)

| # | Step | Result | Observed |
|---|---|---|---|
| A1 | Notifications for the submitted incidents and report | ✓ Pass | 203 unread notifications (incidents, coverage report) |
| B1 | Verify the robbery | ✓ Pass | Verified |
| B2 | Start investigation | ✓ Pass | Under investigation |
| B3 | Close not offered to the Deputy | ✓ Pass | Close not offered |
| B4 | Download the original; custody shows it | ✓ Pass | original identical to the upload (byte for byte); download in custody |
| C1 | Critical kidnapping escalated | ✓ Pass | Escalated, awaiting national acknowledgement |
| D1 | Return the coverage report | ✓ Pass | Returned |
| D2 | Chief resubmits; mark reviewed (locked) | ✓ Pass | resubmitted with 15 personnel; Reviewed and locked |
| E1 | No access decisions without a delegation | ✓ Pass | no delegation: nothing to decide |
| F1 | Deputies cannot delete | ✓ Pass | no delete option |
