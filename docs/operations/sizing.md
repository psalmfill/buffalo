# Sizing

Guidance for planning hardware. Measure after go-live (System health shows
database, evidence and backup sizes) and adjust.

## Minimum (pilot: a few states, up to about 200 users)

| Resource | Minimum | Notes |
|---|---|---|
| CPU | 8 vCPU (x86-64) | Report rendering and ClamAV are the peaks |
| RAM | 16 GB | Container limits total about 12.75 GB; the rest is the OS and file cache. The installer refuses less. |
| System disk | 40 GB | OS and Docker images (about 6 GB per release; keep two) |
| Data disk | 200 GB SSD | Database, evidence, reports, Valkey |
| Backup storage | 2 × data disk, separate device | Repository 1 local; repository 2 elsewhere |

## National rollout (all states, up to about 2,000 users)

| Resource | Recommended |
|---|---|
| CPU | 16 vCPU |
| RAM | 32 GB |
| Data disk | 1 TB SSD (NVMe preferred), expandable |
| Backup storage | 2–3 TB per repository |

With 32 GB, raise the limits in `/etc/buffalo/buffalo.env`:

    BUFFALO_MEM_POSTGRES=8g
    BUFFALO_PG_SHARED_BUFFERS=2GB
    BUFFALO_PG_EFFECTIVE_CACHE_SIZE=6GB
    BUFFALO_PG_WORK_MEM=32MB
    BUFFALO_PG_MAINTENANCE_WORK_MEM=512MB
    BUFFALO_MEM_APP=2g
    BUFFALO_MEM_HORIZON=2g
    BUFFALO_CPUS_POSTGRES=4

Keep `shared_buffers` at about 25% of `BUFFALO_MEM_POSTGRES` and
`effective_cache_size` at about 75%.

## Per-service memory limits (defaults)

| Service | Memory | CPUs | Notes |
|---|---|---|---|
| postgres | 3g | 2 | `shared_buffers` 768MB |
| clamav | 3g | 1 | Signatures need about 1.5 GB in memory (twice while reloading) |
| gotenberg | 1536m | 2 | One Chromium per render |
| app (PHP-FPM) | 1g | 2 | 20 workers (`pm.max_children`) |
| horizon | 1g | 2 | Queue workers |
| valkey | 768m | 1 | Cache, sessions, queues |
| backup (restic) | 768m | 1 | |
| reverb, scheduler, pgbackrest | 512m each | 1 | |
| nginx | 256m | 1 | |

Each is `BUFFALO_MEM_<SERVICE>` / `BUFFALO_CPUS_<SERVICE>`.

## Storage growth

| Data | Estimate | Plan |
|---|---|---|
| Database | About 2–5 KB per incident with history and audit rows; audit tables kept indefinitely | Start with 20 GB; review monthly |
| Evidence | Driven by uploads (photos 2–5 MB, video much more); never deleted by the application | Agree an expected monthly volume per state with the client |
| Reports | Expire automatically (`config/buffalo.php`) | A few GB |
| Database backups | 4 full copies plus differentials and four weeks of WAL | 5 × the compressed database size |
| Evidence backups | Deduplicated, only grows | 1.2 × the evidence volume |

Example: 2 GB of new evidence a month adds about 24 GB a year of evidence,
and about the same again in each backup repository.

## Performance targets

Measured in development with seeded data: pages under 500 ms, map layers
cached per scope, PDF reports in about 1.5 s per document, global search
under 300 ms per module. Load testing at expected national volumes is part
of Phase 9 UAT.
