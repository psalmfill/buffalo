---
title: Reporting and processing an incident
summary: Drafting, the fast critical report, submission, review, investigation, closure and follow-ups.
audience: intelligence
order: 10
---

Every security incident is recorded once, in the LGA where it happened, and
then moves through a review workflow. This guide follows an incident from the
first report to closure.

## Reporting an incident

Open **Incidents → Report incident**. The form is one page in six sections.
You can **save it as a draft at any time** and come back to it later from
**Incidents** (drafts have no reference number and are not counted anywhere).

1. **What happened.** Choose the incident type (grouped by category). The
   type adds its own fields, such as the number of victims for a kidnapping.
   Then a short title, a description and the severity (pre-filled from the
   type; change it if needed). Changing the type after filling its fields
   clears them; you are warned first.
2. **When and where.** The date and time, and how precise it is (exact,
   approximate, or the day only). The LGA, the community (filtered to the
   LGA), the point on the map, and a description of the place.
3. **People.** Casualties (killed, injured, abducted, missing, rescued,
   arrested) by group, with the agency for security personnel; persons involved (choose
   an existing person, describe an unidentified person, or create a new
   person record); criminal groups.
4. **Response.** The agencies that responded, with their formation, role and
   time, and the action taken.
5. **Property affected.** Property damaged or destroyed, optionally linked to
   an organization site.
6. **Classification.** Restricted unless the incident needs more protection
   (see Getting started).

## Fast critical report

When something serious is happening, do not wait to complete the form:

1. Open **Report incident**.
2. Fill in only the **core facts**: type, title, description, severity
   **Critical**, time and LGA.
3. Press **Submit**.

The incident gets its reference number at once and, because it is critical,
it is **escalated to national level automatically**. The State Director, the
Deputy, the Director General and the Deputies covering the state are
notified immediately. Add the people, response, evidence and map point
afterwards: the reporting side can keep editing until the incident is
verified.

## Submission and the reference number

**Submit** sends the incident for review. At the first submission it receives
a reference number such as `BUF/LA/IKJ/2026/000123` (state, LGA, year and a
number that runs per state per year). The State Director and Deputy are
notified (critical incidents as critical, high ones as warnings).

## Review: verify or return (State Director, Deputy)

Open the incident from the notification or from **Incidents**. You have to
act within the review deadline (1 hour for critical, 6 hours for high,
1 day for medium, 2 days for low); after that you are reminded, and at twice
the deadline the national level is told.

- **Verify** when the report is credible and complete enough. An optional
  comment is recorded.
- **Return** when it needs correction. A reason is required; the reporting
  officer is notified, corrects it and submits again.
- The **Possible duplicates** tab shows similar incidents nearby in time and
  place. If it is a duplicate, invalidate it (below) and name the original.

## Investigation and resolution

- **Start investigation** (verified incidents; SD, DSD, DG, DDG).
- **Resolve** with a resolution summary.
- **Reopen** a resolved incident with a reason (SD, DG, DDG).
- **Close** a resolved incident (SD, DG, DDG). A closed incident is
  **locked**: nothing can be changed or added any more.

## Marking an incident invalid

**Invalidate** (SD, DG, DDG) a submitted or verified incident that is false,
a duplicate or entered in error. A reason is required (and the original for a
duplicate). Invalid incidents are locked and never counted in figures.

### Consolidating a duplicate

When the reason is **Duplicate** and you have chosen the original,
**Consolidate into the original** (on by default) first carries anything
useful on the duplicate over to the original. The dialog lists every item and
what will happen to it:

- **Evidence** is moved: the file, its SHA-256 hash and its custody history
  are unchanged, and the custody record shows the move. The original's
  Evidence tab marks it "From BUF/…". Evidence below the original's
  classification is raised to match.
- **Follow-ups** are copied to the original's Timeline, marked "Originally
  recorded on BUF/… by [name] at [time]". The originals stay on the
  duplicate.
- **Persons, criminal groups, agencies and property** are added when the
  original does not already list them; otherwise their notes are added,
  labelled with the duplicate's reference.
- **Casualties** are never moved or added up. The dialog shows both tables;
  if the duplicate's figures are better, amend the original's figures.
- **Flashpoint and threat links** are added where you may manage them;
  **access requests** that cited the duplicate now cite the original.
- Anything above your clearance, or not visible to you, is listed as
  **not consolidated** and left on the duplicate.

You need the right to edit the original (an amendment reason if it is
verified); a closed or invalid original cannot receive content.
Consolidation **cannot be undone**: the duplicate is invalid and locked
afterwards, with its own reference and history.

## Escalation to national level

Escalation is a flag, separate from the status. The State Director or Deputy
can **Escalate** a submitted, verified or investigated incident with a reason;
critical incidents are escalated automatically. The DG and the DDGs covering
the state are notified, and one of them **Acknowledges** it.

## Follow-ups (Timeline)

Use **Add update** on the **Timeline** tab for new intelligence, actions
taken or other updates. Follow-ups cannot be edited or deleted once written,
and can be classified (never below the incident). Follow-ups are possible at
every stage except closed and invalid.

## Editing after verification

From verification until closure, corrections are made by holders of the
amend right (DG, DDG, SD, DSD); each save asks for the reason, which is kept
in the History tab. After submission only the SD, DG or DDG can move an
incident to another LGA; the reference number stays the same.

## Finding incidents

- **Incidents:** filters (status, severity, type, place, period, escalated,
  text), sorting and **saved views** for filters you use often.
- **Incident map:** the same filters on a map, with clustering.
- **Print** on an incident produces a page marked with its classification and
  your name; each print is recorded.

## The incident page tabs

| Tab | Contents |
|---|---|
| Overview | Core facts, type details, map, workflow and action taken |
| People | Casualties with totals, persons, criminal groups |
| Response | Agencies, formations, property |
| Evidence | Files with their integrity and custody ([Evidence](evidence.md)) |
| Timeline | Follow-ups |
| Possible duplicates | Reviewers only |
| History | Every change, who made it and when |
