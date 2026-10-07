# Host hardening

BUFFALO's containers are hardened (non-root, read-only, no capabilities;
[../security/container-hardening.md](../security/container-hardening.md)),
but the host they run on must be hardened too. Apply this before installing.
It is written for Ubuntu Server 24.04 LTS or RHEL 9 family; adapt the
commands for other distributions.

## Operating system

- A supported long-term-support release, minimal install, no desktop.
- Automatic security updates for the OS (`unattended-upgrades` or
  `dnf-automatic`); air-gapped: an internal mirror updated at least monthly.
- Full-disk encryption (LUKS) on the volumes holding `/var/lib/docker` and
  the backup repositories, with the passphrase escrowed like the BUFFALO keys.
- Separate filesystem (or LVM volume) for `/var/lib/docker`, so a full disk
  cannot stop the OS; the backup repositories on separate storage again.
- Clock synchronisation (chrony or systemd-timesyncd) to agency time
  servers. TOTP sign-in and the audit trail depend on it; the installer
  refuses a host whose clock is not synchronised.

## Access

- SSH keys only (`PasswordAuthentication no`, `PermitRootLogin no`), from the
  administration network only, with named accounts for each administrator.
- `sudo` with logging; membership of the `docker` group is equivalent to root,
  so grant it to nobody: run Docker commands through `sudo`.
- Settings and secrets: `/etc/buffalo/buffalo.env` 0640 root:root;
  `/etc/buffalo/secrets` 0700 root:root, files 0444 (only root can enter the
  directory; Docker mounts each file into the containers that need it).

## Network

- Inbound: allow 443 (and 80 for redirects and ACME) from the user networks
  only; SSH from the administration network only; deny everything else.
- Outbound: deny by default. `airgapped`: nothing. `connected` / `cloud`:
  only the destinations in [installation.md](installation.md#the-frontend-network-and-the-host-firewall).
- The frontend egress rule (`scripts/ops/egress-firewall.sh`) is applied by
  the installer; keep it if you manage the firewall with other tools
  (it lives in Docker's `DOCKER-USER` chain).
- Docker publishes ports by writing its own iptables rules, which bypass
  `ufw`/`firewalld` input rules. Bind ports explicitly with
  `BUFFALO_BIND_ADDRESS` (for example the server's internal address) and
  filter in `DOCKER-USER` or at the network firewall.

## Docker daemon

`/etc/docker/daemon.json`:

    {
      "live-restore": true,
      "userland-proxy": false,
      "no-new-privileges": true,
      "icc": false,
      "log-driver": "json-file",
      "log-opts": {"max-size": "20m", "max-file": "5"}
    }

- `live-restore` keeps containers running while the daemon restarts.
- `icc: false` blocks traffic on the default bridge (BUFFALO uses its own
  networks).
- Keep Docker Engine (28+) and Compose (2.33.1+) up to date from Docker's
  repository or the internal mirror.
- Rootless Docker and user-namespace remapping are not supported by the
  current images and volumes layout.

## Monitoring and logs

- Forward the host's journal (sshd, sudo, Docker) and the container logs to
  the agency's log server ([installation.md](installation.md#logs)).
- `auditd` rules for `/etc/buffalo`, `/opt/buffalo`, `/etc/docker` and the
  Docker socket.
- Disk space alerts at 80% for `/var/lib/docker` and the backup storage
  (BUFFALO's System health warns as well).

## Checklist

- [ ] OS updates automatic; disk encryption on; clock synchronised
- [ ] SSH keys only, from the administration network; no `docker` group members
- [ ] Inbound firewall: 443/80 from user networks; outbound denied except listed destinations
- [ ] `egress-firewall.sh status` active
- [ ] `daemon.json` as above; Docker 28+ / Compose 2.33.1+
- [ ] `/etc/buffalo` permissions as above
- [ ] Logs forwarded; auditd rules; disk alerts
- [ ] Backup repository 2 on separate storage (ideally off-site)
