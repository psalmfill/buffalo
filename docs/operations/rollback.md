# Rolling back an upgrade

`upgrade.sh` never rolls the database back automatically. When an upgrade
fails it stops, leaves the site on the maintenance page and prints which
case applies. Decide with the message and the logs, then follow one of the
two procedures below. Both end with the previous release running.

Variables used below (adjust to the installation):

    CONFIG=/etc/buffalo/buffalo.env
    DIR=/opt/buffalo
    PREV=<previous version, BUFFALO_PREVIOUS_VERSION in $CONFIG>
    dc() { (cd "$DIR/current" && sudo docker compose --env-file "$CONFIG" "$@"); }

## A. The upgrade failed before migrations started

(Failures during verification, backups, stopping services, unpacking or
loading images.) The database has not changed.

1. Point the installation back at the previous release:

       sudo ln -sfn "releases/$PREV" "$DIR/current"
       sudo sed -i "s/^BUFFALO_VERSION=.*/BUFFALO_VERSION=$PREV/" "$CONFIG"

2. Start it: `dc up -d --wait --remove-orphans`
3. Check: `dc ps` shows every service healthy.
4. Continue with step 5 of procedure B.

## B. The upgrade failed during or after migrations

The database may be partly migrated. Restore the backup the upgrade took
just before (`upgrade.sh` printed its label, for example
`20261007-101500F`; `dc exec pgbackrest backup-entrypoint.sh pgbackrest info` lists them).

1. Keep the maintenance page on. Stop the application:
   `dc stop app horizon scheduler reverb`
2. Point the installation back at the previous release (procedure A, step 1).
3. Restore the database to the pre-upgrade backup:

       cd "$DIR/current" && sudo scripts/restore/restore-database.sh --project buffalo --set <label> --backup-only

   `--set <label> --backup-only` returns exactly to the pre-upgrade backup.
   Without `--backup-only`, PostgreSQL would replay all archived WAL and
   re-apply whatever the failed migration changed.
   Data entered after the backup was taken is lost; the site was in
   maintenance mode, so there should be none.
4. Verify (keys against the backup's manifest, row counts, encrypted
   fields, audit chains, migrations), then start:

       cd "$DIR/current" && sudo scripts/restore/verify-restore.sh --project buffalo
       dc up -d --wait --remove-orphans

5. Leave maintenance mode:

       docker run --rm --network none --user 0 -v buffalo_maintenance:/m \
           --entrypoint rm "buffalo/valkey:$PREV" -f /m/on

   (`buffalo_` is the `COMPOSE_PROJECT_NAME`.)
6. Sign in, check System health, and report the failure with the upgrade
   output and `dc logs --tail 500` to the BUFFALO maintainers.

Evidence files are never changed by an upgrade, so they need no restore.

## Tested

Exercised in the Phase 9A verification: 0.9.0 → 0.9.1 upgrade, then a
0.9.2 release with a deliberately failing migration, rolled back with this
procedure (docs/handover/phase-9a.md). A table created after the
pre-upgrade backup was confirmed gone after `--set … --backup-only`.
