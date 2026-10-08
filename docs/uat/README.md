# User acceptance testing

BUFFALO's UAT environment runs the **production images** with the
**airgapped** profile and `APP_ENV=uat`: production settings, synthetic data
only, a red **"UAT — TEST DATA ONLY"** banner on every page and a
**Feedback** button.

## Starting it

    make uat-up            # https://localhost:19443 (self-signed certificate)
    make uat-credentials   # new passwords and TOTP secrets (printed once)
    make uat-down          # stops it; its data is kept
    make uat-down PURGE=1  # stops it and deletes its data

To host the UAT environment as a demo behind an existing nginx, see
[`docs/operations/demo-hosting.md`](../operations/demo-hosting.md).

`make uat-up` builds the images from the current source, creates
`.uat/` (settings, secrets, certificate; never committed), migrates, and seeds
with `buffalo:uat:seed`, which **refuses** outside `APP_ENV=uat` and on any
database holding a non-UAT account. The UAT containers refuse to start in
production mode.

Seeded data (all synthetic):

- the demo hierarchy (Lagos and Kano: 3 districts and 12 LGAs each, named
  "Demo LGA …") and synthetic square boundaries;
- 3,000 demo incidents (references `DEMO/…`);
- a person in Kano for the cross-state scenario: **Garba Testperson**, born
  12 April 1985, NIN `98765432101`;
- Lagos and Kano are **live**; Oyo is **not live**.

## Accounts

The credentials (password and TOTP secret) are printed once by `make uat-up`.
Add each TOTP secret to an authenticator app (or a TOTP tool) for that tester.

| Account | Role | Jurisdiction |
|---|---|---|
| admin@uat.buffalo.test | Super admin | System |
| dg@uat.buffalo.test | Director General | National |
| ddg@uat.buffalo.test | Deputy Director General | National |
| sd.lagos@uat.buffalo.test | State Director | Lagos |
| dsd.lagos@uat.buffalo.test | Deputy State Director | Lagos |
| chief.lagos@uat.buffalo.test | LGA Security Chief | Demo LGA LA-1-1 (Lagos) |
| sd.kano@uat.buffalo.test | State Director | Kano |
| chief.kano@uat.buffalo.test | LGA Security Chief | Demo LGA KN-1-1 (Kano) |
| sd.oyo@uat.buffalo.test | State Director | Oyo (not live) |

## The scripts

One per role. Run them **in this order**; later scripts use what earlier ones
created (the incident, threat and requests):

1. [LGA Security Chief](01-lga-security-chief.md)
2. [Deputy State Director](02-deputy-state-director.md)
3. [State Director](03-state-director.md)
4. [Deputy Director General](04-deputy-director-general.md)
5. [Director General](05-director-general.md)
6. [System administrator](06-super-admin.md)

The same scripts are automated by `scripts/uat/run.py`, which signs in as
each tester (password and TOTP), performs every step through the screens'
own endpoints and checks the expected result:

    python3 scripts/uat/run.py --credentials <file with the printed credentials table>

It writes `results/<date>-<script>.md`. Run it on a freshly created
environment (`make uat-down PURGE=1 && make uat-up`): the scripts build on each
other's records. Testers still run the scripts by hand in the browser; the
runner proves the environment and the workflows before they start.

Each step has an expected result. Mark **Pass** or **Fail**; for a failure,
press **Feedback** on that page and describe what happened. Record each run
in `results/` (copy the script, fill in the Pass/Fail column, the date, the
tester and the version shown on the Go-live page).

## Rules for testers

- **Test data only.** Never enter real names, numbers or places.
- One browser profile per account (sessions are single: signing in elsewhere
  signs you out).
- Use the Feedback button for anything unexpected, unclear or slow.
