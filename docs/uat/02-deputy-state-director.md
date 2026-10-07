# UAT script 2 — Deputy State Director

Account: **dsd.lagos@uat.buffalo.test** (Lagos). Run after script 1.

| # | Step | Expected result | Pass/Fail |
|---|---|---|---|
| A1 | Sign in. Open the bell. | Notifications for the submitted incidents (critical one first, in red) and the coverage report. | |
| B1 | Open "UAT robbery at market". **Verify** with a comment. | Status Verified; the reporting officer is notified. | |
| B2 | **Start investigation**. | Status Under investigation. | |
| B3 | Try **Close**. | Not offered (Deputies cannot close). | |
| B4 | Open the evidence photo, **Download original**. | Downloads (restricted: no code asked), identical to the uploaded file; the custody shows the download. | |
| C1 | Open "UAT critical kidnapping". | Shows Escalated, awaiting acknowledgement by national. | |
| D1 | **Coverage reports**: open the Lagos report, **Return for changes** with a reason. | Status Returned; the chief is notified. | |
| D2 | (Chief: edit the report, raise personnel to 15, **Submit** again.) Then **Mark reviewed**. | Status Reviewed; the report is locked (no edit buttons). | |
| E1 | **Access requests → Awaiting my decision**. | Nothing to decide: you hold no delegation from the State Director. | |
| F1 | Open a flashpoint; try to delete it. | No delete option (Deputies hold no delete rights). | |
