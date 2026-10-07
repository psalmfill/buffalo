# BUFFALO-patched nginx (Phase 9A.2): the digest-pinned upstream image with
# Alpine security updates. Upstream user, entrypoint and behaviour unchanged.
ARG UPSTREAM=nginx:1.30.5-alpine3.24@sha256:0985e772fb9f729e6fa0980da05fca5d9c468e870eed43071545afa9d2e27d94
FROM ${UPSTREAM}
ARG UPSTREAM
ARG BUILD_DATE=unknown
RUN apk upgrade --no-cache
LABEL org.opencontainers.image.base.name="${UPSTREAM}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.title="buffalo-patched-nginx" \
      org.opencontainers.image.description="nginx with OS security updates"
