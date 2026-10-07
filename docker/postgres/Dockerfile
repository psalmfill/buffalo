# BUFFALO PostgreSQL image (Phase 8C.1): the pinned PostGIS image plus
# pgBackRest from the same Alpine release. Used by the `postgres` service
# (WAL archive-push runs inside PostgreSQL) and by the `pgbackrest` sidecar
# (scheduled backups, check, verify), so both run the same pgBackRest.
#
# Built on the BUFFALO-patched PostGIS image (docker/patched/postgis, which
# pins the upstream image by digest). PostGIS publishes linux/amd64 only.
# Configuration is baked in (Phase 9A): postgresql.conf and the init scripts.
ARG BASE_IMAGE=buffalo/patched-postgis:dev
FROM ${BASE_IMAGE}

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache pgbackrest=2.58.0-r0 \
    # Layout copied into new volumes on first mount (Docker seeds an empty
    # named volume from the image), so each writer owns its directory:
    # pgBackRest as postgres (70), restic as the application user, which
    # owns the evidence files it backs up.
    && mkdir -p /backup/pgbackrest /backup/restic /backup/manifests \
        /backup-status/pgbackrest /backup-status/restic /var/log/pgbackrest /tmp/pgbackrest \
    && chown postgres:postgres /backup/pgbackrest /backup/manifests /backup-status/pgbackrest /var/log/pgbackrest /tmp/pgbackrest \
    && chown "${UID}:${GID}" /backup/restic /backup-status/restic \
    && chmod 0700 /backup/pgbackrest /backup/restic \
    && chmod 0755 /backup /backup/manifests /backup-status /backup-status/pgbackrest /backup-status/restic

COPY pgbackrest.conf /etc/pgbackrest/pgbackrest.conf
COPY postgresql.conf /etc/postgresql/postgresql.conf
COPY init/ /docker-entrypoint-initdb.d/
COPY backup-entrypoint.sh pgbackrest-scheduler.sh /usr/local/bin/
