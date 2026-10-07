# BUFFALO-patched PostGIS (Phase 9A.2): the digest-pinned upstream image
# with Alpine security updates applied. Upstream user, entrypoint and
# behaviour unchanged, except that `gosu` is removed: it only drops root,
# BUFFALO runs PostgreSQL as postgres from the start (compose user 70:70),
# and the binary carries Go standard library CVEs. Rebuilt weekly
# (.github/workflows/images.yml). PostGIS publishes linux/amd64 only.
ARG UPSTREAM=postgis/postgis:18-3.6-alpine@sha256:ffcf0c4b904e41b9779f8098007fb5a9484025319c18c70cf8e1bcebb742b9b7
FROM ${UPSTREAM}
ARG UPSTREAM
ARG BUILD_DATE=unknown
RUN apk upgrade --no-cache && rm -f /usr/local/bin/gosu
LABEL org.opencontainers.image.base.name="${UPSTREAM}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.title="buffalo-patched-postgis" \
      org.opencontainers.image.description="postgis/postgis with OS security updates, gosu removed"
