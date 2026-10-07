# BUFFALO Valkey (Phase 9A): the digest-pinned Valkey image with the ACL
# entrypoint baked in (Phase 8A; no bind mount).
FROM valkey/valkey:9.1.2-alpine3.24@sha256:48332870af354a799964c0012ae1194a0bf2bf894eb508f945810596dc2d8d11
RUN apk upgrade --no-cache
COPY --chmod=0755 entrypoint.sh /usr/local/bin/buffalo-valkey-entrypoint
ENTRYPOINT ["/usr/local/bin/buffalo-valkey-entrypoint"]
