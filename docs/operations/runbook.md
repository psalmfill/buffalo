# BUFFALO operations runbook

Day-to-day operation of a production installation. Commands assume:

    CONFIG=/etc/buffalo/buffalo.env
    dc() { (cd /opt/buffalo/current && sudo docker compose --env-file "$CONFIG" "$@"); }

Health at a glance: **System health** in the application (super admin), or
`dc ps` (every service should be `healthy`). Alerts for failed backups, WAL
archiving, disk space, the scheduler, queues and ClamAV signatures appear in
System health and are emailed to super admins when mail is configured.

## Starting, stopping, restarting

| Task | Command |
|---|---|
| Start everything | `dc up -d --wait` |
| Stop everything (data kept) | `dc stop` |
| Restart one service | `dc restart horizon` |
| Status | `dc ps` |
| Logs (last hour) | `dc logs --since 1h app horizon` |
| Follow logs | `dc logs -f nginx` |

After a host reboot Docker restarts the services itself (`restart:
unless-stopped`) and `buffalo-egress-firewall.service` (bound to `docker.service`) re-applies the
frontend firewall rule. Check with `dc ps` and
`sudo /opt/buffalo/current/scripts/ops/egress-firewall.sh status`.

## Maintenance mode

nginx shows a static maintenance page (HTTP 503) while the flag file exists
on the `maintenance` volume; the application is not involved.

    # on
    sudo docker run --rm --network none --user 0 -v buffalo_maintenance:/m --entrypoint touch buffalo/valkey:<version> /m/on
    # off
    sudo docker run --rm --network none --user 0 -v buffalo_maintenance:/m --entrypoint rm buffalo/valkey:<version> -f /m/on

`upgrade.sh` does this itself.

## Upgrading

Copy the new release's files to the host (for example
`/var/tmp/buffalo-1.3.0/`), then run the **installed** release's script:

    sudo /opt/buffalo/current/scripts/ops/upgrade.sh /var/tmp/buffalo-1.3.0

It verifies the release with the installed key, refuses older versions,
shows the release notes, asks you to type `upgrade to 1.3.0`, takes a full
database backup and an evidence snapshot, shows the maintenance page, lets
queue workers finish (up to 5 minutes, `--worker-timeout`), loads and
verifies the images, migrates, starts everything, runs smoke checks and
leaves maintenance mode. Plan 15–30 minutes, mostly the backups. If it
fails: [rollback.md](rollback.md). Keep the previous release directory under
`/opt/buffalo/releases/` until the next upgrade.

## Backups and restores

See [../security/backup-and-recovery.md](../security/backup-and-recovery.md).
Quick reference:

| Task | Command |
|---|---|
| Database backup now (full / differential) | `dc exec pgbackrest backup-entrypoint.sh pgbackrest-scheduler.sh now full` |
| Evidence snapshot now | `dc exec backup restic-scheduler.sh now backup` |
| List database backups | `dc exec pgbackrest backup-entrypoint.sh pgbackrest info` |
| Restore drill (isolated, monthly) | `sudo /opt/buffalo/current/scripts/restore/drill.sh` |
| Restore the database | `sudo /opt/buffalo/current/scripts/restore/restore-database.sh --help` |

## Certificates

- Own certificate: replace the file at `BUFFALO_TLS_CERTIFICATE_FILE` and the
  `tls_key` secret file; nginx reloads within 15 minutes (or
  `dc exec nginx nginx -s reload`). Changing the key file requires
  `dc up -d nginx` (secrets are mounted at start).
- ACME: renewals are automatic; check `dc logs certbot`.

## ClamAV signatures

Air-gapped: import new signatures at least weekly
(`scripts/clamav-import-signatures.sh`, see
[../security/malware-scanning.md](../security/malware-scanning.md)). System
health warns when signatures are older than 7 days.

## Users and access

- First super admin: `dc exec app php artisan buffalo:create-super-admin`
- A user locked out of 2FA: a super admin resets their two-factor
  authentication from the user's page (audited).
- Encryption keys and rotation: [../security/key-management.md](../security/key-management.md).

## Troubleshooting

| Symptom | Check |
|---|---|
| A service is `unhealthy` | `dc logs --tail 200 <service>` |
| `app` restarts at boot with "Production configuration check failed" | The listed setting in `$CONFIG` (`APP_DEBUG`, `APP_URL`, cookies, CSP). Values are named, never printed. |
| "Secrets check failed" | A secret file is missing, empty or a development value: `dc run --rm tools php artisan buffalo:secrets:check` |
| Browser shows the maintenance page after an upgrade | The upgrade did not finish: read its output; [rollback.md](rollback.md) |
| Real-time updates do not arrive | `dc logs reverb`; the browser must reach `wss://<server>/app` through nginx |
| PDF reports stuck "queued" | `dc logs horizon gotenberg`; Horizon dashboard under `/horizon` (super admin) |
| Uploads stay "scanning" | `dc logs clamav`; signatures age in System health |
| Disk filling | System health disk alert; backup repository sizes; `docker system df` |

Never run `docker compose down -v`: it deletes the database and evidence
volumes.
