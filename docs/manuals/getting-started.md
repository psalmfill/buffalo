---
title: Getting started
summary: Signing in, two-factor authentication, the dashboard, notifications and search.
audience: all
order: 1
---

BUFFALO is the security management and intelligence reporting system for
local government areas, states and the national level. This guide covers what
every user needs on the first day. Your role's manual then explains your own
work, and the task guides explain each job step by step.

## Your account

Accounts are created by a system administrator; there is no way to sign
yourself up. You receive a **one-time setup link** by email or from an
administrator in person (or by phone) during a training session.

1. Open the setup link. It works once, and for 72 hours. If it has expired or
   was already used, ask an administrator for a new one.
2. Choose a password: at least 12 characters, with upper and lower case
   letters, a number and a symbol. You cannot reuse any of your last five
   passwords.
3. Set up two-factor authentication (next section). You cannot use BUFFALO
   until it is set up.

If your state is not live yet, you can still complete these steps. When you
then try to sign in you will see **"BUFFALO is not yet active for your
state."** You will be able to sign in as soon as your state goes live.

## Two-factor authentication

Every account uses a second factor: a six-digit code from an authenticator app
on your phone (any standard TOTP app). The code changes every 30 seconds.

1. On the setup page, scan the QR code with your authenticator app, or type
   the setup key shown under it.
2. Enter the six-digit code the app shows, to confirm.
3. You are then shown **recovery codes**, once. Write them down and keep them
   somewhere safe and separate from your phone. Each recovery code works once
   if you lose your phone.

If you lose both your phone and your recovery codes, a system administrator
must reset your two-factor authentication. This is recorded in the audit log.

## Signing in and out

1. Enter your email address and password.
2. Enter the current code from your authenticator app.

Things to know:

- **Five failed attempts lock your account** for 15 minutes, and longer if it
  happens again. Administrators are told about every lockout.
- **One session at a time.** Signing in somewhere else signs you out here.
- **Idle timeout.** After 30 minutes without activity you are signed out. A
  warning appears a little before, with a **Stay signed in** button.
- **Twelve hours at most.** Every session ends after 12 hours, even if active.
- Always use **Sign out** (your name, bottom of the menu) on shared computers.

## Confirming it is you (step-up)

Some sensitive actions ask for a fresh code from your authenticator app, even
though you are signed in: for example downloading secret material, large
exports, original evidence above restricted, approving access requests, and
some administration. Enter the current code; it stays valid for 15 minutes.
Recovery codes are not accepted here.

## The screen

- **The menu** on the left shows only what your role may use. The sections are
  Overview, Incidents, Threats & flashpoints, Local Government, Intelligence,
  Security and Administration.
- **The header** shows where you are, the search box and the notification bell.
- **The classification banner** at the top and bottom of the screen reminds
  you of the handling level of what you are looking at.
- **Records you may not see do not exist for you.** Opening a link to a record
  outside your jurisdiction or above your clearance shows "not found". That is
  expected, not a fault.

## The dashboard

The dashboard is your starting point and depends on your role:

- **LGA Security Chief:** your LGA's incidents, threats and flashpoints, your
  coverage report status, and organization sites.
- **State Director and Deputy:** the whole state, LGA by LGA, with items waiting
  for your review.
- **Director General and Deputies:** the national (or zonal) picture, escalated
  incidents and state comparisons.
- **System administrator:** accounts, sign-in security, rollout and onboarding,
  background services. No intelligence content.

Choose the period (7, 30 or 90 days) at the top. Figures never include drafts
or reports marked invalid, and always cover only what you are allowed to see.

## Notifications

The bell in the header shows new notifications: incidents submitted or
returned, escalations, access requests, threats, coverage reports and more.
Critical notifications also appear as a red message on the screen. Click a
notification to open the record. Notifications contain no sensitive details;
open the record to see them.

## Search

Type in the search box in the header to search across incidents (including
by reference number such as `BUF/LA/IKJ/2026/000123`), threat reports,
flashpoints, persons, persons of interest, criminal groups, communities,
organizations, agency formations and places. Results only include what you
may see.

Searching for persons in the **Persons** section works differently: it can
also tell you that a matching record exists in another state. See
[Persons and cross-state requests](persons-and-cross-state-requests.md).

## Classification

Sensitive records carry a classification:

| Level | Who may see it (within their jurisdiction) |
|---|---|
| Restricted (default) | All intelligence roles |
| Confidential | DG, DDG, State Director, Deputy State Director, and the record's creator |
| Secret | DG, DDG, State Director, and the record's creator |

You can never classify a record above your own clearance. Choose the lowest
level that protects the information; over-classifying hides it from people
who need it.

## Getting help

- **Help** in the menu (this section): your role manual, task guides and a
  printable quick reference card.
- The **?** next to a page title opens the guide for that page.
- For account problems (locked out, lost phone), contact your system
  administrator.
