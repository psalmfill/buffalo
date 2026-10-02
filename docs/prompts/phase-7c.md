# BUFFALO — Phase 7C: Analytics

Read `CLAUDE.md` before starting (especially the statistics rule, `IncidentFilters`/`MapFilters` reuse, and `LayerCache`). Read `docs/handover/phase-7b.md` and `docs/handover/phase-6c.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

Dashboards show current operational figures. Analytics answers questions over time and across places: "Are kidnappings rising in the North West this quarter?", "How long does verification take in each state?", "Which threat reports actually materialised?". This step adds an analytics section for decision-makers.

**Rules (from CLAUDE.md):**
- Every figure is computed through the viewer's own scopes and the statistics rule (drafts and invalid incidents excluded).
- Heavy aggregates may be cached with `LayerCache`, keyed by scope signature, never shared across signatures.
- Small numbers are shown as they are (viewers can already see those records); no suppression is needed for scoped data.

---

## Step 7C.1 — Analytics service and filters

- `app/Services/Analytics/` with one class per area (below). Each method takes the viewer, an `AnalyticsFilters` value object, and returns plain data series.
- `AnalyticsFilters` extends or composes `IncidentFilters`: period (presets: last 7/30/90 days, this month, last month, this quarter, last quarter, this year, custom), comparison period (none, previous period, same period last year), granularity (day, week, month, quarter, chosen automatically from the period length but overridable), geography (national, zone, state, LGA, within reach), incident types/categories, severity.
- **Performance:** start with direct queries on the existing indexes. If any analytics endpoint exceeds 1 second uncached at 50,000 incidents, add a nightly-refreshed rollup table (`incident_daily_rollups`: date, state, LGA, type, severity, status group, classification, counts and casualty sums) built in system context. Viewer figures from rollups = rows with `classification` within the viewer's clearance **plus** a live count of incidents the viewer created above their clearance (the creator rule). Rollups must reproduce exactly the same figures as direct queries (test it). Report which approach was needed.

## Step 7C.2 — Analytics areas

Each area is a tab on `/analytics`, built with the Phase 5D chart wrappers (Chart.js), with a data table under every chart and a "comparison" overlay when a comparison period is chosen.

**Incidents**
- Trend over time (line), with the comparison period overlaid.
- By category and by type (bar), with change versus the comparison period (absolute and %).
- By geography: one level below the viewer's selection (national → states, state → LGAs), as a sortable table with counts, change and rate per 100,000 where population exists, plus a link to the choropleth on the operational map with the same filters.
- By severity over time (stacked bar).
- Time patterns: a day-of-week × hour-of-day table (heat-coloured cells) for incidents with `exact` time precision only, with the count of excluded incidents shown.

**Casualties**
- By outcome over time (killed, injured, abducted, missing, rescued, arrested).
- By group (civilian, security personnel, suspect); security personnel casualties by agency.
- Abducted versus rescued over time, for kidnapping-type incidents.
- Top incident types by people killed and by people abducted.

**Response**
- Reporting delay: `submitted_at − occurred_at` (median and 90th percentile), excluding `date_only` precision.
- Verification time: `verified_at − submitted_at` (median, 90th percentile), by severity, against the configured deadlines (percentage within deadline).
- Time to resolve and to close.
- By state or LGA (one level below), sortable, to compare performance.
- Escalation: number escalated, time to national acknowledgement.

**Threats and flashpoints**
- Threat reports by type, urgency and credibility; status outcomes (materialised, mitigated, expired, withdrawn).
- **Materialisation rate by credibility level** (how often "probable" threats actually materialised), which helps calibrate future assessments.
- Flashpoints by concern level over time (from assessments); incidents linked to or near flashpoints versus elsewhere.
- Blackspot suggestions: generated, accepted, dismissed.
- Disseminations (7A): sent, acknowledged, time to acknowledge.

**Coverage**
- Report submission compliance over time by state or LGA.
- Rating distribution over time; LGAs persistently `inadequate` or `critical` (3 or more consecutive periods).
- Personnel and patrol trends from deployments.

**Cross-jurisdiction** (national users only, permission `access_requests.oversee`)
- Requests by requesting and owning state; approval rate; time to decision; escalations; grants active.
- Hits shown and suppressed, by state; officers reaching the soft or hard daily limits.

## Step 7C.3 — Dashboards

- Add a period selector (7, 30, 90 days) to each role's dashboard, applied to all its widgets, defaulting to the current behaviour.
- Add KPI tiles with change versus the previous period: incidents, people killed, people abducted, verification within deadline (%), active immediate threats.
- Every dashboard widget links to the matching analytics view with the same filters.

## Step 7C.4 — Sharing and export of charts

- Analytics views keep all filters in the URL (shareable; each viewer sees only their own scopes).
- Each chart offers **Download PNG** (client-side from the canvas, with the classification marking and "Generated for [name] at [time]" drawn into the image) and **Download CSV** of the chart's aggregate table (server-side, through the viewer's scopes). Both are logged in `record_access_logs` with action `export` and context `analytics`. Full report exports come in 7D.

## Step 7C.5 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `analytics.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `analytics.export` (chart PNG/CSV) | ✓ | ✓ | ✓ | ✓ | ✓ |

Each area also requires its module's view permission; the cross-jurisdiction tab requires `access_requests.oversee`. Navigation: "Analytics" under Overview. Update the matrix, drift tests and `auth.ts`.

## Step 7C.6 — Tests

- Every analytics method against a fixed fixture set, per role: figures equal direct scoped counts; drafts and invalid excluded; clearance and creator rule respected.
- If rollups are used: rollup figures equal direct-query figures for every fixture case, including the creator rule.
- Period, comparison and granularity logic (including quarter boundaries in Africa/Lagos).
- Response-time medians and percentiles, with `date_only` exclusions.
- Materialisation rate calculation.
- Cache keys include the scope signature; no cross-signature sharing.
- PNG and CSV exports logged; CSV built through scopes; markings present.
- Cross-jurisdiction tab denied to non-national users.

## Acceptance checklist

- [ ] The DG compares kidnappings this quarter with last quarter, by state, and sees change and rates.
- [ ] A State Director sees verification performance by LGA against the deadlines.
- [ ] The threats tab shows the materialisation rate by credibility level.
- [ ] A downloaded chart PNG carries the classification marking and the user's name; the export appears in the access log.
- [ ] All analytics endpoints respond in under 1 second at 50,000 incidents; timings and the rollup decision are in the handover.
- [ ] `make test` and `make lint` pass.

## Handover

Write `docs/handover/phase-7c.md` per CLAUDE.md section 9 and print it.
