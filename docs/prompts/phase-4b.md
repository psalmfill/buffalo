# BUFFALO — Phase 4B: Organizations, Sites and Contacts

Read `CLAUDE.md` before starting (especially access rules 2 and 9, and the decisions log entry on organizations). Read `docs/handover/phase-4a.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

Phase 4A delivered agency formations and coverage reports. This step covers the brief's "companies / organizations": relevant companies and organizations in each area, with contact and management information.

**Design (from the decisions log):** an organization such as an oil company can operate in many states. So:

- **`organizations`** is a **national directory entry** holding only non-sensitive identity data (name, type, sector, registration number). It has **no location columns**, so it is not jurisdiction-scoped. It must never hold sensitive content.
- **`organization_sites`** are the organization's physical locations (head office, facilities, terminals, branches). Sites carry `state_id`/`lga_id`, are jurisdiction-scoped and classified, and hold the security-relevant information.
- **`organization_contacts`** belong to a site, carry their own `state_id`/`lga_id` (access rule 9), and have encrypted phone and email.

A user sees every organization's directory entry, but only the sites and contacts in their own jurisdiction.

---

## Step 4B.1 — Organization sectors (reference data)

Add an `organization_sectors` lookup table following the Phase 2A pattern exactly (same shared columns, delete-rejecting trigger, admin tab in `/reference-data`, cached options, idempotent seeding that never overwrites admin edits).

Seed: oil and gas, mining, manufacturing, agriculture, construction, telecommunications, banking and finance, transport and logistics, maritime and ports, markets and retail, hospitality, education, health, media, private security, power and energy, government and public services, non-profit, other.

## Step 4B.2 — Organizations (directory)

`organizations` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `name` | required |
| `search_name` | generated column (`buffalo_unaccent()` pattern), trigram GIN index |
| `other_names` | jsonb array (trading names, former names), included in search |
| `type` | enum: `company`, `ngo`, `government_agency`, `international_organization`, `association_union`, `educational_institution`, `other` |
| `organization_sector_id` | FK (register with `ReferenceUsage`) |
| `rc_number` | nullable; CAC registration number; partial unique index when present |
| `website` | nullable (stored as text only; never fetched or linked automatically) |
| `description` | nullable text; help text states it must contain only public, non-sensitive information |
| `status` | enum: `active`, `dormant`, `closed` |
| `created_by`, `updated_by`, timestamps, soft deletes | |

- `LogsBuffaloActivity`. Not jurisdiction-scoped, not classified.
- Visible to the five intelligence roles via permission. Super admin has no access.
- Creating: duplicate warning using trigram similarity on name and other names (index-backed `%` operator, per CLAUDE.md) and exact `rc_number`; the user can open the existing entry or create anyway.
- **Deletion:** only DG/DDG, only when the organization has no sites (check in system context; the message says only "This organization has sites and cannot be deleted"), with a reason.

## Step 4B.3 — Organization sites

`organization_sites` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `organization_id` | FK |
| `name` | required, e.g. "Bonny Export Terminal" |
| `site_type` | enum: `head_office`, `regional_office`, `branch`, `facility`, `plant`, `terminal`, `depot`, `field_camp`, `warehouse`, `other` |
| `state_id`, `lga_id` | required; composite FK pattern |
| `community_id` | nullable; composite FK |
| `address` | nullable text |
| `location` | nullable point; in-LGA check when boundaries exist |
| `employees_estimate` | nullable integer ≥ 0 |
| `security_relevance` | enum: `low`, `medium`, `high`, `critical_infrastructure` |
| `has_private_security` | boolean |
| `security_provider_organization_id` | nullable FK → organizations; the provider's sector should be private security (warning, not error) |
| `security_notes` | nullable text (threats, past incidents, vulnerabilities) |
| `status` | enum: `active`, `inactive`, `closed` |
| `classification` | default `restricted` |
| `created_by`, `updated_by`, timestamps, soft deletes | |

- `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`; `LogsReads` when classification is `confidential` or `secret`.
- At most one `head_office` site per organization (partial unique index).

`organization_site_formations`: `organization_site_id`, `agency_formation_id`, `arrangement` (enum: `stationed_on_site`, `regular_patrol`, `on_call`, `other`), `notes`, unique pair. Records government security protecting a site. A user may link only formations and sites they can both see.

## Step 4B.4 — Contacts

`organization_contacts` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `organization_site_id` | FK |
| `state_id`, `lga_id` | copied from the site; composite FK (access rule 9) |
| `name` | required |
| `position` | required, e.g. "Managing Director", "Security Manager" |
| `phone` | **encrypted**, normalised like person phones, blind index `phone_bidx` |
| `email` | **encrypted**, lower-cased, blind index `email_bidx` |
| `is_management` | boolean |
| `is_security_contact` | boolean |
| `is_primary` | boolean; at most one primary per site |
| `person_id` | nullable FK → persons: optional link when the contact is also a person on record. Authorise with `link` on the person (access rule 9), never `view`. The link is shown only to users who can see that person. |
| `notes` | nullable text |
| `created_by`, `updated_by`, timestamps, soft deletes | |

- `HasJurisdiction`, `LogsBuffaloActivity`, `LogsReads` (every view of contact details is logged). Encrypted fields and blind indexes are excluded from activity properties.
- Lookup by phone or email (exact, via blind index) within jurisdiction, from the organizations search page: "Who does this number belong to?"
- Contacts are site-level only. Management of a whole organization is recorded on its head-office site.

## Step 4B.5 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `organizations.view`, `organizations.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `organizations.delete` | ✓ | ✓ | | | |
| `organization_sites.delete` | ✓ | ✓ | ✓ | | |

`organizations.manage` covers the directory entry, sites, site-formation links and contacts (within jurisdiction and classification). Super admin: none, except `reference_data.*` for sectors as in 2A. Update `RolesAndPermissionsSeeder::matrix()`, the drift tests and `resources/js/types/auth.ts`.

## Step 4B.6 — UI

Navigation: under a new or existing section, **"Organizations"** (`organizations.view`).

**`/organizations`**: search (name and other names, fuzzy; or exact phone/email contact lookup, detected from the input), filters (type, sector, status; and "has sites in my area"). Results show name, sector, type, and the number of sites **visible to the user**. Never show counts of sites outside the user's jurisdiction.

**`/organizations/{ulid}`**: directory details; visible sites list and map; "Add site" (within the user's jurisdiction).

**`/organization-sites/{ulid}`**: site details with classification badge, map, security notes, private security provider (linked), government security (formations), contacts (primary first; management and security contacts flagged), history.

LGA page: new **"Organizations"** tab listing sites in the LGA, filterable by security relevance, with a map layer.

Dashboards:
- **LGA chief:** sites in the LGA by security relevance.
- **State roles:** critical-infrastructure and high-relevance sites in the state, by LGA; sites without any linked government security.
- **National:** high and critical sites by state and sector.

Run `make wayfinder` after route changes.

## Step 4B.7 — Tests

- Organizations visible to all five intelligence roles; super admin denied; no location columns on `organizations` (schema test).
- Sites and contacts: scope and classification matrix; contacts carry their own jurisdiction and are not visible through the organization or site relationship alone.
- Site counts on search results count only visible sites.
- Deletion rules: organization with sites cannot be deleted; message reveals nothing more.
- Duplicate warning by fuzzy name and exact RC number; `EXPLAIN` confirms trigram index use.
- Contacts: phone/email ciphertext in the database; blind index lookup works with differently formatted phone numbers; every contact view logged; encrypted values never in activity log or other pages' props.
- Contact → person link requires `link` on the person; users who cannot see the person do not see the link.
- One head office per organization; one primary contact per site.
- Site-formation links require both to be visible.
- Sector usage registered: a sector in use shows as in use in reference data admin.

## Phase 4 completion checklist

- [ ] Create an oil company with a head office in one demo state and a terminal in the other; each state's users see only their own site, and both see the directory entry.
- [ ] Record the terminal as `critical_infrastructure`, protected by a Navy base formation, with a security manager contact; look up the manager by phone number written differently.
- [ ] The contact view appears in the access log.
- [ ] The state dashboard lists the terminal among critical sites.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-4b.md` per CLAUDE.md section 9, and `docs/handover/phase-4-summary.md` summarising all of Phase 4: tables, permissions, workflows, and open issues for Phase 5. Print both.
