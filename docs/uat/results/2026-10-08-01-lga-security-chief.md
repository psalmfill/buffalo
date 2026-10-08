# UAT results — 01-lga-security-chief

- Date: 2026-10-08 15:34 (Africa/Lagos)
- Environment: https://demo.buffalo.test:8443 (UAT, synthetic data)
- Run by: scripts/uat/run.py (signed in as each tester, password and TOTP)

| # | Step | Result | Observed |
|---|---|---|---|
| A1 | Sign in; LGA dashboard; UAT banner | ✓ Pass | LGA dashboard for Demo LGA LA-1-1; banner shown |
| A2 | Help: own manual first, no super admin manual | ✓ Pass | 5 role manuals (own first), 9 guides, 5 cards |
| A3 | Contextual help from the dashboard | ✓ Pass | Getting started opens with the "The dashboard" section |
| B1 | Report incident; save as draft | ✓ Pass | draft saved, no reference (01m4dyxajhhaksfbar4kynsr37) |
| B2 | Submit: reference assigned | ✓ Pass | BUF/LA/D11/2026/000001, Submitted |
| B3 | Upload evidence (scanned) | ✓ Pass | accepted after the malware scan (1 item, status ?) |
| B4 | Evidence custody | ✓ Pass | 2 custody entries with name, time and IP |
| B5 | Add update | ✓ Pass | update shown in the Timeline |
| C1 | Fast critical report: core facts only, submit | ✓ Pass | BUF/LA/D11/2026/000002, escalated automatically |
| D1 | Record threat report | ✓ Pass | THR/LA/2026/00001, Active |
| D2 | Record flashpoint; record assessment | ✓ Pass | recorded; assessment set concern High, Active |
| D3 | Request dissemination to Kano | ✓ Pass | request sent to the State Director |
| E1 | Persons search by NIN: record found in Kano | ✓ Pass | "Record found" in Kano; no name or details |
| E2 | Request access (full, urgent, 7 days) | ✓ Pass | request listed as Pending |
| E3 | Persons search by name only: no notice | ✓ Pass | no cross-state notice for a name alone |
| F1 | Coverage report: write and submit | ✓ Pass | Week of 5 Oct 2026: Submitted |
| G1 | Out-of-jurisdiction incident: not found | ✓ Pass | a Kano incident is "not found" for the Lagos chief |
| G2 | Daily situation report | ✓ Pass | ready; PDF downloaded (147062 bytes) |
| G3 | Incident list export not offered | ✓ Pass | offered: sitrep, weekly, incident, lga_profile, coverage, analytics |
