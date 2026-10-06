# Container hardening

Phase 8C.5. Every service in `compose.yml` runs with the least privilege it
needs. `scripts/check-compose-hardening.sh` enforces it in CI (`make ci`
and the GitHub `security` job); `--running` also inspects the live
containers.

## Rules (all services)

- **Images pinned by tag and digest.** Built images (`buffalo-app`,
  `buffalo-postgres`) pin every Dockerfile base image by digest. Update a
  pin only deliberately: pull the new tag, record its digest, rerun the
  tests and the Trivy scan.
- **`no-new-privileges`** and **`cap_drop: [ALL]`**. No service adds any
  capability back: every service runs as a non-root user from its first
  process, so none needs `CHOWN`, `SETUID`, `SETGID` or
  `NET_BIND_SERVICE` (nginx listens on 8080 inside its container).
- **Read-only root filesystems** with `tmpfs` scratch space. Persistent data
  lives only in named volumes.
- **Memory and CPU limits** for a single 16 GB server, overridable with
  `BUFFALO_MEM_<SERVICE>` and `BUFFALO_CPUS_<SERVICE>` (for example
  `BUFFALO_MEM_POSTGRES=6g` on a larger server).
- **Health checks** for every service; `depends_on` waits for them.
- **Networks** (each service joins only what it needs):

| Network | Internal (no gateway) | Members |
|---|---|---|
| `frontend` | no (ports are published here) | nginx |
| `backend` | yes | nginx, app, horizon, scheduler, reverb, postgres, valkey |
| `internal-tools` | yes | gotenberg, clamav, and their clients app, horizon, scheduler |
| `backup` | yes | pgbackrest, backup, postgres |

Because `backend` has no gateway, nothing in the stack can reach the
internet: the air-gapped default. A deployment that sends mail through a
relay, or updates ClamAV signatures online, adds an egress network to
`horizon` (mail) or `clamav` (signatures) in its production compose file
(Phase 9) and allows only that destination in the firewall.

## Per service

| Service | User | Capabilities | Root filesystem | tmpfs | Limits (default) |
|---|---|---|---|---|---|
| app (php-fpm) | `buffalo` (build arg UID) | none | read-only | `/tmp` | 1 GB, 2 CPU |
| horizon | `buffalo` | none | read-only | `/tmp` | 1 GB, 2 CPU |
| scheduler | `buffalo` | none | read-only | `/tmp` | 512 MB, 1 CPU |
| reverb | `buffalo` | none | read-only | `/tmp` | 512 MB, 1 CPU |
| nginx | `nginx` (101), port 8080 | none | read-only | `/tmp`, `/var/cache/nginx` | 256 MB, 1 CPU |
| postgres | `postgres` (70) | none | read-only | `/tmp`; `shm_size` 256 MB | 3 GB, 2 CPU |
| pgbackrest | `postgres` (70) | none | read-only | `/tmp` | 512 MB, 1 CPU |
| backup (restic) | application user (owns the evidence files) | none | read-only | `/tmp` | 768 MB, 1 CPU |
| valkey | `valkey` (999) | none | read-only | `/tmp` (ACL file) | 768 MB, 1 CPU |
| gotenberg | `gotenberg` (1001) | none | read-only | `/tmp`, `/home/gotenberg` | 1.5 GB, 2 CPU |
| clamav | `clamav` (1000), `/init-unprivileged` | none | read-only (config mounted) | `/tmp` | 3 GB, 1 CPU |

Total limits: about 12.8 GB, leaving room for the operating system and its
page cache on a 16 GB server. ClamAV's limit covers a signature reload,
which briefly needs twice the ~1.5 GB it holds.

**The application services in production** (Phase 9 deployment compose):
the `prod` image contains the code, so `storage/` must be a volume
(`app_storage:/var/www/html/storage`) for the read-only root to work. In
development the source mount provides it.

Development relaxations are only in `compose.override.yml`: source mounts,
published ports on 127.0.0.1, the `default` network (Mailpit, Xdebug,
forwarded database ports), and the dev-only Mailpit and Vite services.

## Upgrading an existing installation

Two services used to start as root and now start as their own user. On an
installation created before Phase 8C, fix the ownership of their volumes
once, with the stack stopped:

```sh
docker run --rm -v buffalo_valkey_data:/d alpine:3 chown -R 999:1000 /d
docker run --rm -v buffalo_clamav_data:/d alpine:3 chown -R 1000:1000 /d
```

(PostgreSQL's data already belongs to `postgres`.) New installations need
nothing.

## Checking

```sh
scripts/check-compose-hardening.sh            # static: compose.yml and Dockerfiles
scripts/check-compose-hardening.sh --running  # plus: uid, CapEff, NoNewPrivs per container
```

Last result (6 October 2026, development): all 11 services pass;
every container runs non-root with `CapEff: 0000000000000000`,
`NoNewPrivs: 1` and a read-only root filesystem.
