# BUFFALO-patched Gotenberg (Phase 9A.2): the digest-pinned upstream image
# with Debian security updates, including Chromium from trixie-security
# (Gotenberg pins Chromium at release time; Debian ships its security
# fixes faster). Upstream user (gotenberg), entrypoint, command and health
# check unchanged. PDF rendering is retested after every rebuild
# (ReportInfrastructureTest).
ARG UPSTREAM=gotenberg/gotenberg:8.37.0-chromium@sha256:0d28ae9a96441588ef739623726bd500ad0720b77266c6f1351a13e333fbd61c
FROM ${UPSTREAM}
ARG UPSTREAM
ARG BUILD_DATE=unknown
USER root
RUN set -eux; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get upgrade -y --no-install-recommends; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*
USER gotenberg
LABEL org.opencontainers.image.base.name="${UPSTREAM}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.title="buffalo-patched-gotenberg" \
      org.opencontainers.image.description="gotenberg/gotenberg (Chromium) with Debian security updates"
