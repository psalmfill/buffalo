# Phase 9 summary and the state of the project

Phase 9 made BUFFALO deployable and ready for go-live. It completes the core
project (Phases 0–9). Details: [`phase-9a.md`](phase-9a.md) (packaging,
profiles, releases) and [`phase-9b.md`](phase-9b.md) (go-live readiness).

## 1. What Phase 9 delivered

| Area | Delivered |
|---|---|
| Production packaging (9A) | `compose.prod.yml`: release images only, configuration baked in, read-only roots, a secret file per container, logs to stderr with rotation. A boot check refuses unsafe settings: debug, http, cookies, report-only CSP, an open Horizon, rollout gating off, an idle timeout over 30 minutes. |
| Deployment profiles (9A) | `airgapped` (no egress from any container), `connected` and `cloud` (an egress network for nginx only), with opt-in overlays for mail (horizon), online ClamAV signatures and ACME certificates. A host firewall rule stops nginx's published network from connecting out. |
| TLS (9A) | TLS 1.2 and 1.3 only, modern AEAD ciphers, HTTP redirected to HTTPS, OCSP stapling where egress exists. testssl.sh reports no weak protocols, ciphers or known attacks. |
| Patched images (9A) | PostGIS, nginx, ClamAV, Gotenberg, Valkey and restic are rebuilt weekly with current OS packages and scanned by Trivy. Dated exceptions fail CI after their review date. |
| Signed releases (9A, 9B) | `make release`: an images archive and a bundle, with a signed `SHA256SUMS` (`ssh-keygen -Y sign`), `images.lock`, release notes, and the Trivy result of the release images (`security-scan.json`). |
| Install, upgrade, rollback (9A) | `install.sh --profile …`, `upgrade.sh <release>` (verified with the installed key; full backup and evidence snapshot first; maintenance page; smoke checks) and `rollback.md` (restore to the pre-upgrade backup with `--backup-only`). All were exercised, including a failed upgrade and its rollback. |
| Production data (9B) | `buffalo:data:status`, the data loading guide, and demo generators absent from production deployments. |
| Onboarding (9B) | CSV import of users (previewed, one transaction, no links). Setup links are emailed in batches or revealed one at a time in a handover session. Onboarding progress per state is shown. |
| Pilot rollout (9B) | States go live one at a time. Officers of a state that is not live cannot sign in but can enrol during training. |
| Help (9B) | In-app manuals (getting started, six role manuals, eight task guides), contextual "?" links, and printable RESTRICTED quick reference cards. |
| UAT (9B) | `make uat-up`: production images, `APP_ENV=uat`, synthetic data, accounts for every role, banner and feedback. Six role scripts, and a runner that executes them (67/67 steps passing). |
| Go-live check (9B) | `buffalo:go-live:check`: 17 technical items and 8 recorded client decisions (append-only, hash-chained), with a report for the client's records. |

## 2. The whole system (Phases 0–9)

| Phase | Built |
|---|---|
| 0 | Docker stack, offline maps (Leaflet and PMTiles), CI |
| 1 | Hierarchy; authentication with mandatory TOTP, lockout and single session; six roles with fail-closed jurisdiction scoping; tamper-proof audit; notifications; user management; role dashboards |
| 2 | Reference data and dynamic fields; LGA profiles, communities, religious institutions, vulnerable population (aggregates only), classified community security notes; the classification gate |
| 3 | Persons (encrypted NIN, phone, address and date of birth, with blind indexes), political offices, persons of interest, criminal groups; cross-jurisdiction "record found" notices, access requests, delegation and time-limited grants |
| 4 | Agency formations, security coverage reports, the organization directory with sites and contacts |
| 5 | Incidents: the form, evidence with integrity and chain of custody, the review workflow, references, escalation, follow-ups, real-time updates, lists, map, print |
| 6 | Boundaries; flashpoints and blackspots with assessments; threat reports; the operational map with server-side aggregation; blackspot suggestions |
| 7 | Dissemination of threats and flashpoints across borders; global search; analytics; reports and exports (Gotenberg PDFs, OpenSpout XLSX/CSV) with markings and expiry |
| 8 | Least-privilege database roles, secrets, key management, hash-chained audit; CSP, step-up, export controls, malware scanning, route authorisation; backups (pgBackRest, restic), restore drills, monitoring and alerts, container hardening |
| 9 | Deployment, releases, go-live readiness (above) |

**Security model in one paragraph.** Two layers always apply:
permissions decide what a user may do, and jurisdiction scoping decides
which records they may see. Classification narrows within the jurisdiction
and never widens it. Records outside a user's reach do not exist for them
(404). Records cross jurisdictions in only two ways: person grants, which
are approved, read-only and time-limited, and disseminations of threats and
flashpoints, which are read-only and revocable. Classification is a hard
ceiling in both. Everything is audited in hash-chained, append-only tables.
Nothing at runtime reaches the internet.

## 3. Deploying it

Read in this order:

1. [`docs/operations/host-hardening.md`](../operations/host-hardening.md)
2. [`sizing.md`](../operations/sizing.md)
3. [`installation.md`](../operations/installation.md)
4. [`data-loading.md`](../operations/data-loading.md)
5. [`runbook.md`](../operations/runbook.md)

| Profile | Command | Egress | Releases arrive |
|---|---|---|---|
| Air-gapped | `sudo sh install.sh --profile airgapped` | none | signed bundle (images archive) carried in |
| Government network | `sudo sh install.sh --profile connected [--mail-egress] [--clamav-online] [--acme]` | nginx (OCSP), plus the overlays chosen; restrict at the host firewall | bundle, or `--registry` (digests verified against the signed manifest) |
| Cloud | `sudo sh install.sh --profile cloud …` | as connected; add cloud firewall rules | as connected |

Then, in every profile:

1. Create the first super admin (`buffalo:create-super-admin`).
2. Load the owner's data (`data-loading.md`).
3. Configure backup repository 2.
4. Run a restore drill.
5. Import users and hand over setup links.
6. Record the client decisions.
7. Make the pilot state live.
8. Run `buffalo:go-live:check` until it passes.

Upgrades: `/opt/buffalo/current/scripts/ops/upgrade.sh <new release>`.
Rollback: `rollback.md`.

## 4. What the client must still decide or provide

**Provide**

1. The **owner's reference data**: `senatorial_districts.csv`, `lgas.csv`,
   optional `wards.csv`, and the LGA (and state) boundary GeoJSON with its
   attribution. No real place data is in the system yet.
2. **The release signing key.** The committed public key is a development
   TEST key; the owner creates the real one (`scripts/ops/release-key.sh`)
   on an offline machine and publishes its fingerprint separately.
3. The **server(s)**: 16 GB RAM minimum (32 GB for national rollout), disks
   as in `sizing.md`, and separate storage for backup repository 2.
4. The **TLS certificate** (or ACME in connected/cloud), and the **mail
   relay** if mail is used.
5. **Key and passphrase escrow**: two sealed copies, custodians named,
   verified at a drill.
6. The **user list** as CSV, and the **training plan** for handover
   sessions.

**Decide** (recorded in the Go-live page; the check will not pass without them)

1. RPO and RTO (default 15 minutes and 4 hours).
2. Retention periods, or "keep indefinitely".
3. Escrow custodians.
4. Sign-off of the accepted ZAP findings.
5. An independent penetration test, or its deferral with a reason
   (`pentest-guide.md`).
6. Handling of quarantined malware.
7. Mail enabled or not.
8. Sign-off of the reference data (incident types and fields, threat types,
   agencies, sectors).

Also: the **deployment profile**, the **pilot states** and their order,
the ClamAV signature routine in air-gapped mode, and the review of the 15
dated vulnerability exceptions (due 5 December 2026).

## 5. Known limitations

- **No real place data.** Development and UAT use clearly synthetic Lagos
  and Kano LGAs and square boundaries. Map and choropleth performance must
  be re-measured once the 774 real boundaries are loaded.
- **Release signing:** test key only (above).
- **Scale is proven on synthetic data only.** Search thresholds, blackspot
  parameters and analytics were tuned on demo data, and the dev
  measurements (about 50,000 incidents) are on Apple Silicon emulating
  amd64. Load testing at national volumes on the real server is part of
  UAT.
- **No merging** of duplicate person, person of interest or incident
  records; political offices cannot be re-assigned (carried from Phase 3).
- **Air-gapped ClamAV** depends on the weekly offline signature import; the
  go-live check and System health warn after 7 days.
- **The frontend firewall rule** (nginx no-egress) needs Linux and root; on
  other hosts it is a documented manual step.
- **UAT runner:** it checks behaviour through the screens' endpoints, not
  visual layout; manual UAT by testers remains necessary.
- **Mail:** off by default. Notifications are in-app; setup links are
  handed over in person or by phone.

## 6. Recommended next steps

1. **Before go-live:**
   - receive the owner's data and real key;
   - build the first signed release;
   - install on the target server;
   - complete manual UAT with the client's testers (the scripts in
     `docs/uat/`);
   - commission the penetration test;
   - load test at expected national volumes;
   - record the decisions;
   - pilot one state.
2. **Operations:** monthly restore drills; weekly patched-image rebuilds
   (the CI workflow); quarterly review of exceptions, access grants and
   audit anchors; an off-site copy of the audit anchors.
3. **Phase 10 (optional extensions):**
   - an **offline-capable PWA** for LGA officers with poor connectivity:
     draft incidents offline, submit on reconnect;
   - a **mobile app** for field reporting with photo evidence: the same API
     and policies, device binding, remote wipe;
   - **integrations**, only through approved, documented interfaces (for
     example national ID verification or agency situation feeds), each
     reviewed against the no-external-calls rule and the deployment
     profile;
   - **merging** of duplicate persons and incidents;
   - **passkeys** as a second factor (deliberately removed in Phase 1;
     reconsider with the client).
