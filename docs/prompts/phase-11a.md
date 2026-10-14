# BUFFALO — Phase 11A: Person Role Types, State Lens and Dashboard Colours

Read `CLAUDE.md` before starting, especially the "Phase 11 (post-review changes)" entry. Read `docs/handover/phase-10-merging-summary.md`.

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

The client reviewed the UAT demo and requested changes. Phase 11 delivers them in five steps:

- **11A (this step):** editable person role types; a state lens for national users; configurable dashboard colours.
- **11B:** rich text editing (Tiptap) on long text fields.
- **11C:** arrests.
- **11D:** organization logos and site photos.
- **11E:** printing and downloading of flashpoints and blackspots; exports for every list.

---

## Step 11A.1 — Person role types

Person roles are currently a fixed enum (`political_leader`, `traditional_ruler`, `religious_leader`, `community_leader`, `person_of_interest`). The client needs to add types such as youth leader, women leader or market leader.

1. New lookup table `person_role_types` following the Phase 2A reference data pattern exactly (shared columns, delete-rejecting trigger, admin tab in `/reference-data`, cached active options, idempotent seeding that never overwrites admin edits), plus `is_system` (boolean).
2. Seed the five existing types with their current codes and `is_system = true`. For system types, admins may change the **label**, description and sort order, but never the code, and cannot deactivate them. Their behaviour stays tied to the code:
   - `person_of_interest`: set only through the POI actions (Phase 3B); never offered in role pickers.
   - `political_leader`: added automatically with political offices (Phase 3B).
   - `traditional_ruler`, `religious_leader`: required for community and religious institution links (Phase 3A).
3. Reference these codes through constants on the model (for example `PersonRoleType::POLITICAL_LEADER`), never as scattered string literals. Search the codebase for every use of the old enum and convert it; list the places in the handover.
4. Migration: add `person_role_type_id` to `person_roles`, fill it from the enum value, make it required, drop the enum column, and keep the unique constraint as (`person_id`, `person_role_type_id`). Register the usage with `ReferenceUsage`.
5. Seed three additional, non-system types as examples the client can rename or deactivate: `youth_leader` (Youth leader), `women_leader` (Women leader), `market_leader` (Market or trade association leader).
6. Everywhere roles appear (person form checkboxes, filters, persons search filter, senatorial district and LGA leadership pages, hit category logic, duplicate finder, merge rules, analytics, exports), use the lookup. Deactivated types stay visible on existing persons but cannot be newly assigned.
7. The LGA **Leadership** tab and senatorial district page group community-level leaders by role type, including new types.

## Step 11A.2 — State lens for national users

The DG, DDGs and other top officials should be able to see the country **state by state** as easily as nationally. This changes what they look at first, **not** what they can see: scopes are unchanged.

1. **National dashboard opens with a state grid.** One card per state within reach (all 37 for the DG; a zoned DDG's states only), showing: incidents in the last 7 and 30 days (with change), critical incidents open, escalations unacknowledged, active `immediate` threats, overdue verifications, people killed and abducted in 30 days, and coverage compliance for the last period. Cards are sorted by an **attention score** (escalations unacknowledged, critical open and immediate threats weighted highest; weights in `config/buffalo.php`), with a toggle for alphabetical order and grouping by zone. Each card's border colour reflects its attention level. All figures follow the statistics rule and the viewer's scopes.
2. **State dashboard for national users:** clicking a card opens `/dashboard/states/{state}`, which renders the **same** dashboard a State Director of that state sees (the same components and figures), with actions limited to what the national user is allowed to do. A zone view (`/dashboard/zones/{zone}`) does the same for a geopolitical zone.
3. **State lens in the header** (national users only): a picker "Viewing: All of Nigeria / [Zone] / [State]", stored in the session. When set:
   - dashboards open on that state or zone;
   - every list (incidents, threats, flashpoints, persons, POIs, coverage, formations, organizations, arrests from 11C) opens with its state or zone filter pre-set to the lens, and the filter chip shows it;
   - the operational map opens zoomed to the state or zone;
   - analytics default their geography to it;
   - global search ranks results in that state first (still returning others).
   An explicit filter or URL parameter always overrides the lens; a clearly visible chip shows the lens on every page, with one click to clear it.
4. Users with state or LGA jurisdiction see no lens control; their scope already fixes their view.
5. Tests: lens values limited to states or zones within reach (a zoned DDG cannot pick an outside state); lens never changes scopes (records outside reach stay invisible); lists, map, analytics and search respond to the lens; URL filters override it; state grid figures equal the per-state dashboard figures; attention score ordering.

## Step 11A.3 — Configurable dashboard colours

1. A **Theme** page under Administration (permission `system.settings`, super admins; add `theme.manage` and give it to super admin and DG):
   - **Brand colours:** primary and accent, each for light and dark mode.
   - **Chart palette:** 8 colours used in order by every Chart.js wrapper, for light and dark mode.
   - **KPI tile colours:** a colour per tile type (incidents, casualties, threats, verification, coverage), light and dark.
   - **Not configurable:** severity colours (low, medium, high, critical), classification banner colours and urgency colours. These carry fixed meanings across the system and in printed reports; show them read-only on the page with that explanation.
2. **Contrast checks:** every configured colour is validated against the backgrounds it is used on (WCAG AA: 4.5:1 for text, 3:1 for chart elements and borders against the card background), in both modes. Failing choices are rejected with the measured ratio and a suggested nearest passing colour.
3. **Live preview** of a sample dashboard (tiles, a bar and a line chart) in both modes before saving; **Reset to defaults**.
4. Storage: a `ui_theme` setting (single JSON document, validated), cached, audit-logged as a named activity event with old and new values. Applied as CSS custom properties in a `<style>` tag carrying the page's CSP nonce (no `unsafe-inline` for scripts; styles follow the existing CSP decision). Chart wrappers read the palette from the CSS variables, so changes apply without a rebuild.
5. PDFs generated by Gotenberg use the same palette for charts.
6. Tests: validation and contrast rejection; severity and classification colours unchanged by any theme; CSS variables rendered with the nonce; charts and PDFs pick up the palette; permission checks.

## Step 11A.4 — Permissions

| Permission | SA | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `theme.manage` | ✓ | ✓ | | | | |

Role types use the existing `reference_data.*` permissions. The state lens needs no permission (national jurisdiction). Update the matrix, drift tests, `auth.ts` and the route inventory.

## Acceptance checklist

- [ ] The DG's dashboard opens with 37 state cards, the most urgent first; clicking Lagos shows exactly what the Lagos State Director sees.
- [ ] With the lens set to Kano, the incident list, map and analytics open on Kano; a URL filter for Lagos overrides it; clearing the lens returns to national.
- [ ] A zoned DDG can only choose states in their zones.
- [ ] An admin adds "Youth leader" as a role type; it appears in person forms and on the LGA Leadership tab. System types cannot be renamed by code or deactivated.
- [ ] The super admin changes the chart palette; a too-pale colour is rejected with its contrast ratio; the accepted palette appears on dashboards and in a PDF report. Severity colours are unchanged.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-11a.md` per CLAUDE.md section 9 and print it.
