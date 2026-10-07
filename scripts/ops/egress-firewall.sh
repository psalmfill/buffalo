#!/bin/sh
#
# Host firewall rule for BUFFALO's frontend network (Phase 9A.1). Linux only;
# run as root.
#
# nginx must publish ports, and Docker can only publish ports from a network
# with a gateway, so Docker alone cannot stop nginx opening connections out.
# This rule drops NEW connections leaving the frontend bridge for anywhere
# else; replies to inbound HTTPS connections still flow. In the connected
# and cloud profiles, nginx reaches out (OCSP stapling) through the separate
# egress network instead, which this rule does not touch.
#
# Usage: scripts/ops/egress-firewall.sh apply|remove|status [--bridge NAME] [--dry-run]
#
#   --bridge NAME  The frontend bridge (default buffalo-front, the
#                  BUFFALO_FRONTEND_BRIDGE setting)
#
# The rule does not survive a reboot (or a Docker Desktop VM restart):
# install.sh installs a systemd unit, buffalo-egress-firewall.service, that
# re-applies it every time docker.service starts.
set -eu

action=${1:-}
[ -n "$action" ] && shift
bridge=buffalo-front
dry_run=false
while [ $# -gt 0 ]; do
    case "$1" in
        --bridge) bridge=$2; shift 2 ;;
        --dry-run) dry_run=true; shift ;;
        -h|--help) action=help; shift ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

rule="-i $bridge ! -o $bridge -m conntrack --ctstate NEW -m comment --comment buffalo-frontend-no-egress -j DROP"
run() { if $dry_run; then echo "would run: $*"; else "$@"; fi; }
present() { iptables -C DOCKER-USER $rule 2>/dev/null; }

case "$action" in
    apply)
        command -v iptables >/dev/null || { echo "iptables (or iptables-nft) is required." >&2; exit 1; }
        iptables -L DOCKER-USER -n >/dev/null 2>&1 || { echo "No DOCKER-USER chain: start Docker first." >&2; exit 1; }
        if present; then echo "ok    rule already present for $bridge"; else run iptables -I DOCKER-USER $rule; echo "added rule for $bridge"; fi ;;
    remove)
        while present; do run iptables -D DOCKER-USER $rule; $dry_run && break; done
        echo "removed rule for $bridge" ;;
    status)
        if present; then echo "active: new outbound connections from $bridge are dropped"; else echo "INACTIVE: $bridge can open outbound connections"; exit 1; fi ;;
    *)
        sed -n '2,/^set -eu/p' "$0" | sed '$d; s/^# \{0,1\}//'; [ "$action" = help ] || exit 2 ;;
esac
