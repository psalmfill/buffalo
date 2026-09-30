# Phase 4 summary — Security agencies, coverage and organizations

Date: 2026-09-30

Phase 4 is complete:

- **4A:**
  - three Phase 3 carry-overs (date of birth in the persons search, live end of access, oversight pagination);
  - agency formations;
  - security coverage reports.
- **4B:** the organization directory, sites and contacts.

Detailed handovers: `phase-4a.md` and `phase-4b.md`.

The final checks passed: 1023 Pest tests (6197 assertions) plus 13 Vitest tests, lint, CI, and the Phase 4 completion checklist in the browser (see `phase-4b.md`).

## Tables added in Phase 4

All intelligence tables follow the project rules:

- `HasJurisdiction` on their own `state_id`/`lga_id` with the composite FK pattern;
- a ULID in URLs;
- authors, timestamps and soft deletes, where applicable;
- `LogsBuffaloActivity`.

| Table | Step | Scope | Notes |
|---|---|---|---|
| `agency_formations` | 4A | jurisdiction + classification | A security agency's physical presence (command → division → station…).<br>Triggers: a state agency stays in its state; the parent is of the same agency; no cycles; location in the LGA.<br>Temporary formations have dates and are ended by a daily job. Trigram name search. Views logged. |
| `coverage_reports` | 4A | jurisdiction + classification | Per LGA and period (weekly / monthly / quarterly / ad hoc), with period boundaries CHECKed and one per LGA and period.<br>Workflow draft → submitted → returned → reviewed; reviewed is **locked by triggers**. Plain `timestamp` workflow columns. Views logged. |
| `coverage_report_deployments` | 4A | own jurisdiction (FK to the report's place) + the report's visibility | Agency, optional formation (same agency and state, trigger), counts, patrol frequency. |
| `coverage_deployment_communities` | 4A | through the deployment | Communities of the report's LGA (composite FK). |
| `coverage_reminders` | 4A | system | Reminders sent, once per scope, place and period. |
| `organization_sectors` | 4B | reference data | Phase 2A lookup pattern, 19 seeded values. |
| `organizations` | 4B | **national directory** (no location, not classified) | Non-sensitive identity only.<br>`search_name` with a trigram index; unique RC number when present.<br>Deleted only without sites (sanctioned yes/no check). |
| `organization_sites` | 4B | jurisdiction + classification | Security relevance up to critical infrastructure, private security and provider, security notes, location in the LGA. One head office per organization. Views of confidential and secret sites logged. |
| `organization_site_formations` | 4B | visible only when both the site and the formation are | Government security protecting a site. |
| `organization_contacts` | 4B | own jurisdiction (FK to the site's place) + the site's visibility | Phone and email **encrypted** (ciphertext CHECKs; blind indexes `phone_bidx`, `email_bidx`). One primary per site. Optional person link (`link` required). Every view logged. |

## Permissions added in Phase 4

Key: **DG** — Director General; **DDG** — Deputy Director General; **SD** — State Director; **DSD** — Deputy State Director; **LGA** — LGA Security Chief. Super admin holds none of these; sectors use the existing `reference_data.*`.

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `formations.view`, `formations.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `formations.delete` | ✓ | ✓ | ✓ | | |
| `coverage.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `coverage.create` | | | ✓ | ✓ | ✓ |
| `coverage.review` | | | ✓ | ✓ | |
| `organizations.view`, `organizations.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `organizations.delete` | ✓ | ✓ | | | |
| `organization_sites.delete` | ✓ | ✓ | ✓ | | |

Every permission is combined with jurisdiction and classification in the policies. Formations, reports, sites and contacts are never grantable across jurisdictions; only persons are (access rule 8).

## Workflows

### Coverage reports (4A)

| Transition | Who | Rules and effects |
|---|---|---|
| Create draft | LGA chief (own LGA); SD or DSD (any LGA of the state) | One per LGA and period; the period must match its boundaries. |
| Edit | The creator, or the SD or DSD of the state; draft or returned only | Deployments are saved as a full list (update, add, soft-delete). |
| Submit | As edit | Needs a rating and a summary, the gaps unless adequate, and a deployment unless critical.<br>A complete **snapshot** goes in the activity log. SD and DSD are notified; a **critical** rating also notifies the DG and the DDGs covering the state, with severity `critical`. |
| Return | SD, DSD | Reason required; editable again; the submitter is notified. |
| Mark reviewed | SD, DSD | Optional comment; snapshot; **locked** (policies and database); the submitter is notified. |
| Discard | The creator, drafts only | Soft delete. |
| Overdue | System, daily | After the grace days following the last completed period: LGA chiefs of missing LGAs and each SD (list of LGAs), once per period. |

Computed on each report:

- totals by agency;
- uncovered communities;
- comparison with the previous report (personnel change, rating change, newly uncovered communities).

The sidebar shows a badge for reports awaiting the reviewer.

### Cross-jurisdiction carry-overs (4A.0)

- **Name plus date of birth** in the persons search can give a *Likely match* hit; a name alone never does.
- **`AccessGrantEnded`** (grant ULID only) closes an open person page when a grant is revoked or expires; the page also closes at `expiresAt`.
- **Oversight lists** are paginated at 25 per page, keeping the filters.

### Organizations (4B)

- **Directory:** create or edit by any intelligence role, with a duplicate warning (fuzzy name and other names, exact RC number). Delete: DG or DDG, only without sites; the message reveals nothing more.
- **Sites:** created only in an LGA within reach; one head office per organization. Government security links need both the site and the formation to be visible.
- **Contacts:** on a site; one primary. Lookup by phone (any format) or email, exact, within jurisdiction. Every contact view is logged (`site_page`, `lookup`).

### Dashboards (end of Phase 4)

| Dashboard | Phase 4 figures |
|---|---|
| LGA chief | Current coverage report status and due date; last rating; uncovered communities; organization sites by relevance. |
| State roles | Every LGA's coverage report status and rating for the current period, and reports awaiting review; critical and high organization sites by LGA; sites without government security. |
| National | Coverage compliance by state for the last completed period; LGAs rated inadequate or critical; critical and high organization sites by state and sector. |

## Sanctioned scope bypasses added in Phase 4

All live in `App\Support\Jurisdiction`, the only place allowed; the architecture tests still pass.

- **`OfficerNames`:** officer names only, for workflow records that cross levels, such as who returned or reviewed a report.
- **`OrganizationSites::anyExist`:** an audited `runAsSystem` giving a yes/no answer, for the organization deletion rule.

Query-builder reads of ids only (no content) are used for:

- period uniqueness;
- the head office rule;
- the formation parent walk;
- coverage snapshots.

## Open issues for Phase 5

- **Pre-existing `timestamptz` columns show times one hour late.**
  - Affected: `lga_profiles.last_reviewed_at`, `persons_of_interest.last_sighted_at` and `last_reviewed_at`.
  - Cause: the Postgres session time zone is UTC while the app writes Africa/Lagos wall-clock values.
  - Fix: set the `pgsql` connection `timezone` to `config('app.timezone')` and convert existing data. This is a decision for the owner, since it touches earlier phases' data. New Phase 4 columns use plain `timestamp`.
- **Incidents (Phase 5) will link to formations, reports, sites and persons.**
  - Child tables must carry their own `state_id`/`lga_id` (access rule 9).
  - Person links must authorise `link`.
  - Only visible sites and formations may be linked (the 4B pattern).
- **Selects that will outgrow plain lists:**
  - formation parent choices (1,000);
  - coverage deployment formations (2,000);
  - security providers (2,000);
  - site formation choices (1,000).

  These need searchable pickers once real data arrives.
- **Coverage reports:** changes to covered communities while in draft are not audited individually (the submitted and reviewed snapshots are complete).
- **National figures** count a report or site above the viewer's clearance as missing or absent. The DG and DDGs have full clearance, so this affects nobody today.
- **The directory relies on officers keeping descriptions public;** nothing checks description content automatically.
- **Carried over from Phase 3:**
  - no merging of duplicate persons or POIs;
  - offices cannot be re-assigned;
  - no marker clustering;
  - `clearHistory()` on sign-out, and key backup and rotation (Phase 8).
- **Reference data:** the dev database still uses synthetic districts and LGAs until the owner supplies the CSVs.
