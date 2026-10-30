---
title: System administrator (super admin)
summary: Accounts, onboarding, rollout, system health, backups and the go-live check. No intelligence content.
audience: role
role: super_admin
order: 5
---

The system administrator runs BUFFALO itself. **You have no access to
intelligence content**: incidents, persons, threats and every other record
are invisible to you. Your menu is the **Administration** section.

## Accounts

**Users** lists every account.

- **New user:** name, email, phone, service number, rank, role and
  jurisdiction (state for State Directors and Deputies, state and LGA for LGA
  chiefs, optional zones for a DDG). You are asked for your authenticator
  code.
- **Import:** many accounts from a CSV file: the file is checked row by row
  and previewed (new, already exists, error) before anything is created;
  nothing is created while any row has an error. Super admin accounts are
  always created one at a time.
- **Setup links:** new accounts have none until you issue them:
  - one user: **Issue setup link** on the user's page (email, or shown once);
  - many: **Users → Onboarding**, filter by state, role and progress, select
    users, then **Email setup links** (when mail is enabled) or **Start
    handover session** to reveal each officer's link one at a time in a
    training session. Each reveal is recorded; links are never exported.
- **Unlock** a locked account, **Reset 2FA** for a lost phone, **Deactivate**
  (with a reason) when someone leaves; their sessions end at once. The last
  active super admin cannot be deactivated.
- **Change role / jurisdiction** when an officer is posted elsewhere; the
  posting history is kept.

## Rollout

**Rollout** lists every state with its onboarding progress (setup pending,
link issued, 2FA not enrolled, ready). **Make live** lets a state's officers
sign in; until then they see "BUFFALO is not yet active for your state". A
reason and your authenticator code are required, and the change is recorded.
**Set not live** signs them out at their next action. National users are
never affected.

## Hierarchy

**Hierarchy** (shared with the DG) lists the 37 states with their senatorial
districts and LGAs. States are fixed: only the **capital** can be edited.
Districts, LGAs and wards can be **created, renamed, deactivated and
reactivated**, never deleted, under the same rules as the official CSV import
(unique codes and names, districts within their state, wards within their LGA).
Every change needs your authenticator code and a reason and is recorded.
Codes are set on create and never change; to correct one, fix the official CSV
and re-import. Deactivated entries stay on the records that already use them.
A warning shows when the national totals differ from 109 districts and 774 LGAs.

## Monitoring

- **System health:** services, queues, the scheduler, backups (database and
  evidence, both repositories), disks, audit chain verification, key
  fingerprints, the TLS certificate and open alerts. Alerts also reach you as
  notifications (and email for critical ones when mail is enabled).
- **Login history:** every sign-in, failure and lockout.
- **Audit log:** every change.
- **Queue monitor:** background jobs.

## Go-live

**Go-live** shows the latest go-live check: every technical item that must
pass (production settings, TLS, no demo data, the full hierarchy and
boundaries, backups, a restore drill, malware signatures, audit chains,
vulnerability exceptions and the security scan) and the client decisions
that must be recorded. Record each decision with **Record**, naming who
decided for the client; you are asked for your authenticator code. Records
are never changed; a new one replaces the previous.

## Operations

Installing, upgrading, backups, restores and the restore drill are done on
the server by the operations team: see the operations runbook delivered
with BUFFALO.
