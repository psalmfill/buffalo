# UAT script 6 — System administrator

Account: **admin@uat.buffalo.test**. Can run at any time.

| # | Step | Expected result | Pass/Fail |
|---|---|---|---|
| **A** | **No intelligence content** | | |
| A1 | Sign in. Dashboard. | System overview, rollout and onboarding panel; no incidents, persons or maps. | |
| A2 | Try `/incidents`. | Forbidden or not found. | |
| **B** | **Bulk onboarding** | | |
| B1 | **Users → Import**: download the template; fill 5 rows (a DG, a State Director for Oyo `OY`, a Deputy for `LA`, an LGA chief for `LA` + an LGA code from the Lagos LGA list, and one row with an unknown state `XX`). Upload. | Preview: 4 New, 1 Error ("Unknown state code"); the Create button is disabled. | |
| B2 | Remove the bad row, upload again, **Create 4 accounts** (authenticator code). | 4 accounts created, no setup links; you land on Onboarding filtered to "Setup pending". | |
| B3 | Select two of them, **Start handover session**. Reveal the first link, copy it, hide it. | One link shown at a time; the Audit log shows "Account setup link issued" with delivery handover, never the link. | |
| B4 | Open the revealed link in a private window and complete setup (password, TOTP). | The account becomes Ready on the Rollout page. | |
| **C** | **Pilot rollout** | | |
| C1 | Sign in as **sd.oyo** (in another browser profile). | After password and code: "BUFFALO is not yet active for your state." | |
| C2 | **Login history**: sd.oyo. | "Refused: state not live yet". | |
| C3 | **Rollout**: **Make live** Oyo with a reason (authenticator code). | Oyo Live; the change is in the Audit log with the reason. | |
| C4 | Sign in as **sd.oyo** again. | The State Director dashboard for Oyo. | |
| **D** | **Monitoring and go-live** | | |
| D1 | **System health**. | Services healthy; the go-live card shows "Not ready" (UAT has demo data). | |
| D2 | **Go-live**. | Technical items fail where expected (demo data, test accounts); decisions not recorded. | |
| D3 | Press **Feedback**, write a note. Open **All feedback**, **Export CSV**. | The note with page and role; the CSV downloads. | |
