# Handover — Phase 4B: Organizations, sites and contacts

Date: 2026-09-30

## Summary

Phase 4B adds the brief's "companies / organizations" in three layers:

- **The organization directory:** a national entry with non-sensitive identity data only (name, other names, type, sector, RC number, website, public description, status). It has no location and is not jurisdiction-scoped or classified.
- **Sites:** head office, terminals, facilities… They are jurisdiction-scoped and classified, and hold the security information: relevance, private security and provider, security notes, and the government formations protecting the site.
- **Contacts:** per site, with their own jurisdiction (access rule 9), encrypted phone and email (blind indexes), and every view logged. An optional link to a person on record is authorised by `link` and shown only to those who can see the person.

It also adds:

- **organization sectors** (reference data, seeded);
- a **contact lookup by phone or email** from the organizations search;
- the LGA page's **Organizations** tab;
- **dashboard figures** at LGA, state and national level;
- an **"In use"** marker on reference data values.

Checks:

- `make test`: 1023 Pest tests (6197 assertions), 44 of them new, plus 13 Vitest tests.
- `make lint` and `make ci` pass.
- The Phase 4 completion checklist passed in the browser.

Commits:

| Step | Commit |
|---|---|
| 4B.1–4B.7 | `feat(organizations): add the organization directory, sites and contacts` |
| — | `docs: add phase 4b handover and phase 4 summary` (this file, the summary, README, prompt) |

## Phase 4 completion checklist

| Item | Result |
|---|---|
| Create an oil company with a head office in one demo state and a terminal in the other; each state's users see only their own site, and both see the directory entry | ✅ As `chief.lagos`: **Gulf of Guinea Petroleum Ltd** (company, Oil and gas, RC 778812 stored as `RC778812`), created through the form (the duplicate check found nothing), then the **Lagos Head Office** site (head office, high relevance, location set) in Demo LGA LA-1-1. As `sd.kano`, the organizations search showed the entry with **0** visible sites; the SD then added the **Kano Inland Fuel Terminal** in Demo LGA KN-1-1. Tinker, each user through their own scopes: `chief.lagos` → Lagos Head Office; `sd.lagos` → Lagos Head Office; `sd.kano` → Kano Inland Fuel Terminal. |
| Record the terminal as `critical_infrastructure`, protected by a Navy base formation, with a security manager contact; look up the manager by phone number written differently | ✅ The terminal was created as **Critical infrastructure**. "Naval Base Kano Riverine" (NN, a dev formation created in Tinker) was linked on the site page as *Stationed on site*. Contact **Ibrahim Danladi, Security Manager**, entered as `0805 123 4567` and `I.Danladi@GoGPetroleum.example`, flagged Primary and Security; shown as `+2348051234567` · `i.danladi@gogpetroleum.example`. The search for `+234 (805) 123-4567` returned "Contacts with this phone number in your jurisdiction: Ibrahim Danladi, Security Manager · Kano Inland Fuel Terminal · Gulf of Guinea Petroleum Ltd · Demo LGA KN-1-1 LGA". The database holds `nacl:…` ciphertext. |
| The contact view appears in the access log | ✅ `record_access_logs` for the contact: `sd.kano view site_page` (the site page) and `sd.kano view lookup` (the phone search). |
| The state dashboard lists the terminal among critical sites | ✅ `sd.kano` dashboard, "Critical infrastructure and high-relevance sites": Demo LGA KN-1-1 → Kano Inland Fuel Terminal · Gulf of Guinea Petroleum Ltd · *Critical infrastructure*. "Sites without government security": None, since the terminal is protected. |
| `make test`, `make lint` and `make ci` pass | ✅ |

## What was built

### 4B.1 — Organization sectors

- `organization_sectors` follows the Phase 2A lookup pattern:
  - shared columns and the code-format CHECK;
  - `buffalo_reject_reference_delete()` triggers for DELETE and TRUNCATE.
- Wiring:
  - model `OrganizationSector` (`ReferenceModel`);
  - `ReferenceTable::OrganizationSectors` (slug `organization-sectors`), which gives the admin tab and the cached options;
  - the factory.
- `ReferenceDataSeeder` adds the 19 sectors through `createMissing`, so seeding is idempotent and never overwrites admin edits.
- Usage is registered (`organizations.organization_sector_id`).
- **The reference data list now shows an "In use" badge** on values referenced by records (`in_use`, a yes/no from the `ReferenceUsage` registry). The prompt's test asks that "a sector in use shows as in use", and no generic indicator existed before.

### 4B.2 — Directory

- **Migration `2026_10_05_000001_create_organization_tables`, table `organizations`:**
  - `search_name` is a generated column over `buffalo_search_text(name || other_names)` (unaccent), with a trigram GIN index.
  - The RC number is normalised to upper case with spaces and dashes removed, has a format CHECK, and a partial unique index among non-deleted entries.
  - CHECKs on type, status, a non-blank name, and `other_names` being an array.
  - **No location columns** (a schema test checks this).
- **`Organization` model:** `LogsBuffaloActivity`, soft deletes, and no jurisdiction or classification. `search()` uses the `%` and `<%` trigram operators inside `PersonSearch::withThresholds`.
- **`OrganizationPolicy`:** permission only.
- **Duplicate warning:** `POST /organizations/similar` matches the name and each other name with `%` or `<%` at 0.6 (index-backed), plus the exact RC number. The create form shows the matches, with the options to open one or **Create anyway**.
- **Deletion (`DeleteOrganization`):** DG/DDG only, with a reason. `App\Support\Jurisdiction\OrganizationSites::anyExist()` is a sanctioned bypass: an audited `runAsSystem`, yes/no only. When sites exist, the only message is "This organization has sites and cannot be deleted."

### 4B.3 — Sites

- **`organization_sites`:** the prompt's columns. Database guarantees:
  - composite FKs `(lga_id, state_id)` → `lgas` and `(community_id, lga_id)` → `communities`;
  - `UNIQUE (id, lga_id, state_id)`, for the contacts' composite FK;
  - CHECKs, including that a site's provider is not its own organization;
  - the location-in-LGA trigger (the Phase 2 function);
  - `organization_sites_one_head_office`: a partial unique index per organization.
- **`OrganizationSite` model:** `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, `LogsReads`. The controller logs views only when `logsViews()` is true, that is for confidential or secret sites.
- **`OrganizationSitePolicy`:**
  - permission plus own jurisdiction plus classification;
  - `createIn(Lga)`;
  - delete needs `organization_sites.delete`.
- **`OrganizationSiteRequest`:**
  - The LGA is chosen once, and must be in reach.
  - The head office rule is checked across all sites of the organization (ids only; the index is the backstop).
  - The community must be in the LGA; the location in Nigeria and in the boundary.
  - The provider must be another directory entry. A provider outside the private security sector is allowed, with a warning in the form and on the site page (`is_private_security`).
  - The classification must be assignable by the user.
- **`SaveOrganizationSite`:** location changes are a `location_changed` event.
- **`organization_site_formations`** (ULID, arrangement, notes, unique pair):
  - model `OrganizationSiteFormation`, with a global scope: visible only when **both** the site and the formation are visible;
  - `SiteFormationController`: `update` on the site, plus `view` on the formation; the events `site_formation_linked` and `site_formation_unlinked`.

### 4B.4 — Contacts

- **`organization_contacts`:**
  - composite FK `(organization_site_id, lga_id, state_id)` → the site, so a contact's place always equals its site's;
  - composite FK to `lgas`;
  - phone and email are **ciphertext only** (CHECKs, as for persons);
  - `organization_contacts_one_primary`: a partial unique index per site.
- **`OrganizationContact` model:**
  - `UsesCipherSweet`, with `phone_bidx` and `email_bidx`;
  - phone normalised by `PhoneNumber::normalise` (like persons); email lower-cased;
  - a saving guard for partial rows;
  - blind indexes only for present values;
  - encrypted fields excluded from the activity log; a `protected_fields_changed` event names the fields only;
  - `HasJurisdiction` on its **own** columns, **and** a global scope requiring the site to be visible, which carries the site's classification;
  - `LogsReads`.
- **`OrganizationContactPolicy`:** permission, the contact's own jurisdiction, and view or update on the site.
- **`OrganizationContactRequest`:**
  - The phone must be a Nigerian number.
  - At most one primary per site.
  - `person` needs `link` on the person (access rule 9); absent on edit means unchanged, so a person the user cannot see is kept.
- **`SaveOrganizationContact`:** person link changes are a `person_link_changed` event with **ULIDs only**.
- **Views logged:** every contact shown on the site page (context `site_page`) and in a lookup (context `lookup`).
- **Contact details** (decrypted phone and email) appear only on the site page's contacts list and in lookup results. A test fetches the other pages and checks that the values are absent.
- **Lookup:** the organizations search detects an email (contains `@`) or a phone number (digits and punctuation that normalise to a Nigerian number). It then matches contacts by exact blind index **within jurisdiction** (`whereBlind`), capped at 50.

### 4B.5 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `organizations.view`, `organizations.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `organizations.delete` | ✓ | ✓ | | | |
| `organization_sites.delete` | ✓ | ✓ | ✓ | | |

Super admin has none of these; sectors are managed with the existing `reference_data.*`. Changes:

- the `Permission` enum;
- an `'organizations'` block in `RolesAndPermissionsSeeder::matrix()`;
- the drift tests (`RolesAndPermissionsTest`, `navigation.test.ts`);
- `types/auth.ts`.

### 4B.6 — UI

- **Navigation:** **Organizations** was added to the existing **Security** section.
- **`/organizations`:**
  - one search box for a name (fuzzy), a phone number or an email (exact contact lookup; results note that each is logged);
  - filters: type, sector, status, "Has sites in my area";
  - the column "Sites you can view" (a `withCount` through the site scopes);
  - in lookup mode only the contact results are shown.
- **`/organizations/{ulid}`:** directory details; visible sites (map coloured by relevance, plus a table); "Add site"; history; delete for DG/DDG.
- **`/organizations/create` and `/edit`:** the "public, non-sensitive only" notice; other names; sector; RC number; website ("stored as text; never opened by BUFFALO"); the duplicate warning.
- **`/organization-sites/{ulid}`:**
  - classification and relevance badges; details; map;
  - security notes; the provider, linked, with the sector warning;
  - **government security**: linked formations, plus an add form listing visible formations of the state;
  - **contacts**: primary first, with Primary, Management and Security badges; inline add and edit form with the person picker; a hidden-person notice; delete with a reason;
  - history.
- **LGA page:** the **Organizations** tab lists sites in the LGA, filterable by security relevance, with the map layer.
- **Dashboards** (`OrganizationFigures`, through the scopes, closed sites excluded):
  - **LGA:** sites by relevance (four tiles).
  - **State:** critical and high sites grouped by LGA, and sites without linked government security (top 50, most relevant first).
  - **National:** critical and high counts by state and sector.

### 4B.7 — Tests (44 new)

- **`OrganizationsTest` (35):**
  - no location columns;
  - the five intelligence roles see the directory, and super admin is denied;
  - RC number normalisation and uniqueness;
  - duplicate check: fuzzy name, other name and exact RC number;
  - `EXPLAIN` over 20,000 rows uses `organizations_search_name_trgm`;
  - deletion refused with a site the deleter cannot even see, with exactly the fixed message; deletion rights;
  - sector usage shows as "In use";
  - the site jurisdiction matrix (15 roles); classification, with logged views for confidential and secret only;
  - search counts, the organization page, and "in my area" count only visible sites;
  - site creation in reach, location event, one head office (HTTP, including a head office the user cannot see, and the database);
  - the provider rule and sector warning;
  - formation links need both visible, and a link to a hidden formation is not shown;
  - site delete rights;
  - the LGA tab and relevance filter;
  - dashboards across jurisdiction and classification.
- **`ContactsTest` (9):**
  - ciphertext at rest, the database refuses plaintext, normalisation, and no values in the activity log;
  - lookup by three phone formats and by email in another case, with each view logged, and nothing for another state;
  - the contact jurisdiction and classification matrix, and nothing through `$site->contacts()` for an out-of-jurisdiction viewer;
  - site-page views logged, and contact values absent from the organization, list, LGA-tab, dashboard and history pages;
  - one primary (HTTP and database);
  - the contact's place is locked to its site's (FK);
  - the person link needs `link` (another LGA's person or a secret person is refused), a hidden link is flagged, never named, and kept on edit, and the event carries ULIDs only;
  - edit and delete rights, 404 out of jurisdiction;
  - no contact value in the application log.

## Deviations and conflicts

- **Navigation placement:** "Organizations" sits in the existing **Security** section (the prompt allowed a new or existing section).
- **Duplicate check operators:** plain `%` similarity against `search_name` is diluted, because `search_name` also holds the other names; `Niger Delta Petrolem Co` was missed. The check uses `%` **or** `<%` (word similarity), both at 0.6 and both served by the trigram index. The prompt names `%`; `<%` is the same index-backed approach.
- **Reference data "In use" badge:** added to all lookup tables, as above. It was needed to meet the prompt's sector test.
- **Site views are logged only for confidential and secret sites,** as the prompt says. Contacts are always logged.
- **Contacts are visible only while their site is visible** (a global scope). This is in addition to their own jurisdiction; without it, a contact of a secret site would be visible to an LGA chief, since contacts have no classification column.
- **A new sanctioned bypass, `App\Support\Jurisdiction\OrganizationSites`,** for the deletion check (audited `runAsSystem`, yes/no only). The architecture test from 3C still passes: no `runAsSystem(` outside `App\Support\Jurisdiction` except `PersonMatchService`.
- **Head office uniqueness is validated across all of an organization's sites with the query builder (ids only),** so a user is told when a head office they cannot see exists. This reveals only that the organization has a head office somewhere; the index enforces it anyway.
- **Organization and LGA of a site are fixed once created,** as for formations in 4A.
- **The site-formation link table has `created_by` and timestamps but no soft deletes.** It is a link row, so removal is a delete, audited as `site_formation_unlinked` on the site.

## New packages

None.

## Known issues and notes

- **The provider list and the formation choices are capped** (2,000 directory entries and 1,000 visible formations) and are plain selects. A searchable picker would be better once the directory grows.
- **The directory is visible nationwide by design.** It relies on officers keeping descriptions public; the form says so. There is no automated check of description content.
- **The contact lookup is exact only** (blind indexes), as required. A partial number finds nothing.
- **Carried over:** the pre-existing `timestamptz` columns that show times an hour late (see the Phase 4A handover) are unchanged.
- **Dev database changes:**
  - the 19 sectors (seeded);
  - "Naval Base Kano Riverine" (NN formation in Demo LGA KN-1-1, created in Tinker);
  - **Gulf of Guinea Petroleum Ltd**, with Lagos Head Office (LA-1-1) and Kano Inland Fuel Terminal (KN-1-1, linked to the naval base, contact Ibrahim Danladi);
  - permissions seeded; Horizon and the scheduler restarted;
  - the test password reset on `sd.kano`, `sd.lagos` and `chief.lagos`.

  `make fresh` resets the data.
- **Your `CLAUDE.md` edits** are still uncommitted; I left them out of my commits.

## For the next phase (5, incidents)

- **Incidents can link to sites and formations** through the scoped models. Any child table must carry its own `state_id`/`lga_id` (access rule 9), and writes that take a person must authorise `link`.
- **Contact values are sensitive:** only `OrganizationPresenter::contact()` exposes them, and its callers log the view.
- **Organization names are not sensitive, site information is.** Incident pages naming an affected organization may show the directory entry, but only visible sites.
