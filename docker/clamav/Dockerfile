# BUFFALO ClamAV (Phase 9A): the BUFFALO-patched ClamAV image with its
# configuration and supervising entrypoint baked in (no bind mounts).
ARG BASE_IMAGE=buffalo/patched-clamav:dev
FROM ${BASE_IMAGE}
COPY --chmod=0755 entrypoint.sh /buffalo-entrypoint.sh
COPY clamd.conf freshclam.conf /etc/clamav/
ENTRYPOINT ["/buffalo-entrypoint.sh"]
