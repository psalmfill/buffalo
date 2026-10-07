---
title: What is logged and why
summary: The audit trail, record access logs, sign-in history and why they exist.
audience: all
order: 80
---

BUFFALO holds sensitive information about people and security operations. To
protect it, and to protect officers who use it properly, everything that
matters is recorded. **These records cannot be changed or deleted by anyone
using BUFFALO**, and the database detects any alteration.

## What is recorded

| Record | What it contains |
|---|---|
| Activity log (audit) | Every record created, changed or deleted: who, when, from which address, and what changed (before and after). Workflow steps (submit, verify, close, approve, revoke, ...) with their reasons. |
| Record access log | Every view of a person, person of interest and classified record; every evidence view and download; every print and export. |
| Sign-in history | Every sign-in, failed attempt, lockout, sign-out, expired session, confirmation with your authenticator code, and sign-in refused because a state is not live. |
| Search log | That a search was made and how many results it gave. The search text itself is **not** kept (only a fingerprint of it). |
| Cross-state matches | Every "record found" notice shown, or suppressed because of classification. |

Passwords, two-factor secrets, setup links and encrypted fields (NIN, phone
numbers, addresses, dates of birth) are **never** written to any log.

## Who can see the logs

- The audit log: the Director General and system administrators.
- Exports review: the DG and DDGs.
- Sign-in history: system administrators.
- Evidence custody: anyone who can see the evidence item.
- The owning state sees how often its records were found by other states.

## Why it matters

- **Accountability:** every decision about a person's information can be
  traced to an officer and a reason.
- **Evidence:** the custody record shows that evidence was handled properly.
- **Detecting misuse:** unusual searching, exporting or downloading is
  reported to the national level automatically.
- **Protecting you:** a complete record shows that you acted within your
  authority.

## Integrity

The audit tables are linked by cryptographic fingerprints (a hash chain).
Every night BUFFALO verifies the whole chain; any alteration, insertion or
deletion is reported to administrators and the Director General at once.
