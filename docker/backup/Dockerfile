# BUFFALO evidence backups (Phase 9A): the digest-pinned restic image with
# the schedule script baked in (no bind mount).
FROM restic/restic:0.19.1@sha256:136600b6ff6843d61d355f7f71f460a166429f35de6fd11b568fece3c9a4d510
COPY --chmod=0755 restic-scheduler.sh /usr/local/bin/restic-scheduler.sh
ENTRYPOINT ["/usr/local/bin/restic-scheduler.sh"]
