# UAT script 1 — LGA Security Chief

Account: **chief.lagos@uat.buffalo.test** (Demo LGA LA-1-1, Lagos).

| # | Step | Expected result | Pass/Fail |
|---|---|---|---|
| **A** | **Sign in and the dashboard** | | |
| A1 | Sign in with the password and the TOTP code. | The LGA dashboard shows only Demo LGA LA-1-1. The red banner reads "UAT — TEST DATA ONLY". | |
| A2 | Open **Help**. | Getting started, your role manual first (marked "Your role"), the other role manuals except the super admin's, the task guides and the cards. | |
| A3 | Press **?** next to the dashboard title. | The Getting started guide opens at "The dashboard". | |
| **B** | **Report and submit an incident** | | |
| B1 | **Report incident**: type Armed robbery, title "UAT robbery at market", description, severity High, time today, your LGA, a community, a map point, one injured adult male, the Police as responding agency. **Save draft**. | Saved as a draft; no reference number. It appears under Incidents → drafts. | |
| B2 | Open the draft, **Submit**. | Reference `BUF/LA/…/2026/…` assigned; status Submitted. | |
| B3 | **Evidence → Upload evidence**: a JPEG photo, classification Restricted. | The photo is accepted (scanned), with a thumbnail. | |
| B4 | Open the photo, expand **Custody**. | Upload and view entries with your name, time and IP. | |
| B5 | **Timeline → Add update**: "Two suspects seen fleeing north." | The update appears; it cannot be edited. | |
| **C** | **Fast critical report** | | |
| C1 | **Report incident**: type Kidnapping, title "UAT critical kidnapping", description, severity **Critical**, time, LGA only. **Submit**. | Reference assigned; badge "Escalated" (Critical severity). | |
| **D** | **Threats and flashpoints** | | |
| D1 | **Record threat report**: Communal clash, credibility Probable, urgency Priority, source Community, expiry in 14 days. | Reference `THR/LA/2026/…`; status Active. | |
| D2 | **Record flashpoint**: "UAT market flashpoint", radius 500 m, inside your LGA. Then **Record assessment**: concern High, status Active, review date next week. | The flashpoint shows concern High; the assessment is in its history. | |
| D3 | On the threat report, choose **Request dissemination** to Kano with a reason. | The request is sent to the State Director. | |
| **E** | **Persons and a cross-state request** | | |
| E1 | **Persons** search: NIN `98765432101`. | No result in Lagos; a **"Record found"** notice for Kano, with no name or details. | |
| E2 | **Request access**: reason of 30+ characters, scope Full, urgency Urgent, 7 days. | Request sent; listed in Access requests → My requests as Pending. | |
| E3 | Persons search: "Garba Testperson" without a date of birth. | No cross-state notice (a name alone never produces one). | |
| **F** | **Coverage report** | | |
| F1 | **Coverage reports → New report**: your LGA, weekly, current period; **Add deployment** (Police, 12 personnel, 2 vehicles, the communities covered if any); rating Partial with a gap. **Submit**. | Status Submitted; the State Director and Deputy are notified. | |
| **G** | **Limits** | | |
| G1 | Open the URL of a Kano incident (from the Incident map of another user, or `/incidents/` + a Kano incident ULID given by the test lead). | "Not found". | |
| G2 | **Reports**: generate a Daily situation report. | Ready notification; the PDF shows RESTRICTED top and bottom, "Generated for UAT LGA Chief Lagos …" and your name as a watermark. | |
| G3 | Reports: the incident list export is not offered. | Not available for your role. | |
