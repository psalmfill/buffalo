# UAT script 3 — State Director

Accounts: **sd.lagos@uat.buffalo.test**, and **sd.kano@uat.buffalo.test** where
stated. Run after scripts 1–2.

| # | Step | Expected result | Pass/Fail |
|---|---|---|---|
| **A** | **Incident to closure (sd.lagos)** | | |
| A1 | Open "UAT robbery at market" (Under investigation). **Resolve** with a summary. | Status Resolved. | |
| A2 | **Close**. | Status Closed; a lock banner; no edit, evidence or update actions. | |
| A3 | **Print**. | A print view with RESTRICTED top and bottom and "Printed by UAT State Director Lagos on …". | |
| **B** | **Threat dissemination (sd.lagos)** | | |
| B1 | Open the chief's dissemination request and accept it, or open the threat and **Disseminate** to Kano with a reason. | Shared with Kano; listed under the threat's sharing panel. | |
| B2 | Sign in as **sd.kano**: **Shared threats & flashpoints**. | The Lagos threat appears read-only, without History. **Acknowledge** it. | |
| **C** | **Cross-state approval (sd.kano)** | | |
| C1 | **Access requests → Awaiting my decision**: the request from chief.lagos. | Shows requester, reason, scope Full, 7 days, Urgent. | |
| C2 | **Approve** for 7 days, scope Profile only. | Asks for the authenticator code (step-up); then Approved. | |
| C3 | Sign in as **chief.lagos**: open the grant from Access requests → Grants. | Garba Testperson's profile, read-only, with a "viewing under a grant" notice; no NIN or phone (Profile scope); no LGA. | |
| **D** | **Delegation (sd.lagos)** | | |
| D1 | **Access requests → Delegations → Delegate** to the Deputy from today, with a reason. | Active delegation listed; dsd.lagos can now decide requests. | |
| D2 | Revoke it. | Ended; dsd.lagos can no longer decide. | |
| **E** | **Reports and exports with step-up (sd.lagos)** | | |
| E1 | **Reports**: Incident list export, XLSX, all of Lagos. | Queued and ready (large or secret exports ask for the authenticator code first). | |
| E2 | Download it. | First rows carry the classification of the most sensitive incident included (SECRET with the demo data) and "Generated for …"; no free-text descriptions; no person names. | |
| E3 | **Suggested blackspots**: accept or dismiss a suggestion (if any). | Accepted creates a blackspot; dismissed disappears. | |
