# UAT script 5 — Director General

Account: **dg@uat.buffalo.test** (national). Run after scripts 1–4.

| # | Step | Expected result | Pass/Fail |
|---|---|---|---|
| A1 | Sign in. Dashboard and bell. | National picture; critical notifications for the critical incident. | |
| B1 | **Administration → Reference data**: add a threat type "UAT test threat". | Added; it appears in the threat report form. Deactivate it again. | |
| B2 | **Administration → Audit log**: filter by user sd.lagos. | The closure, export and delegation entries with reasons. | |
| C1 | Open a Lagos incident and **Invalidate** it as a duplicate of the robbery. | Status Invalid; locked; no longer counted on dashboards. | |
| D1 | Help → **Quick reference cards** → Director General. | A one-page PDF marked RESTRICTED with "Generated for UAT Director General …". | |
