---
title: Quick reference — System administrator
summary: One page: accounts, onboarding, rollout and monitoring.
audience: role
role: super_admin
order: 5
---

**You see:** accounts and the system only. No intelligence content.

## Accounts

- **New user** or **Users → Import** (CSV, previewed; nothing created while
  any row has an error). Authenticator code required.
- **Users → Onboarding:** select users → **Email setup links** or **Start
  handover session** (reveal one link at a time; each reveal recorded).
- Single user: **Issue setup link** · **Unlock** · **Reset 2FA** ·
  **Deactivate** (reason) · **Change role / jurisdiction**.

## Rollout

- **Rollout:** onboarding progress per state; **Make live** / **Set not
  live** with a reason and your authenticator code.

## Every day

- **System health:** services, queues, scheduler, backups (both
  repositories), disks, audit chains, TLS certificate, alerts.
- **Login history:** failures and lockouts.

## Go-live

- **Go-live:** the latest check; **Record** each client decision (who
  decided, when, details). Records are never changed.

## Rules

- Never share setup links in writing; hand them over in person or by phone.
- Deactivate leavers the same day.
- Keep your own authenticator recovery codes offline.
