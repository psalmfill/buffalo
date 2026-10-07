# BUFFALO-patched ClamAV (Phase 9A.2): the digest-pinned upstream image
# (Debian variant: multi-arch) with Debian security updates. Upstream user,
# entrypoints and health check unchanged.
ARG UPSTREAM=clamav/clamav-debian:1.5.4@sha256:9bb8712a50f0e75166e936c452cd82dd5e5be0b85586598930b5bbb84a99a578
FROM ${UPSTREAM}
ARG UPSTREAM
ARG BUILD_DATE=unknown
USER root
RUN set -eux; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get upgrade -y --no-install-recommends; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*
LABEL org.opencontainers.image.base.name="${UPSTREAM}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.title="buffalo-patched-clamav" \
      org.opencontainers.image.description="clamav/clamav-debian with OS security updates"
