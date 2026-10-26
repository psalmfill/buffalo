#!/usr/bin/env bash
#
# Edge mode port check: renders the real Compose configuration (the release's
# compose files with the settings template, deploy/buffalo.env.example) for
# each edge mode and asserts exactly what is published:
#
#   direct  nginx: 0.0.0.0:80 -> 8080 and 0.0.0.0:443 -> 8443 (all
#           interfaces), with and without ACME
#   proxy   nginx: 127.0.0.1:$BUFFALO_EDGE_PORT -> 8081 only (default 8090),
#           with and without the UAT site password
#   local   (UAT) 127.0.0.1:19080 -> 8080 and 127.0.0.1:19443 -> 8443
#
# and that no other service publishes anything. Run by `make ci`. Needs
# Docker (docker compose config) and Python 3.
set -euo pipefail
cd "$(dirname "$0")/.."
export DOCKER_UID=${DOCKER_UID:-1000} DOCKER_GID=${DOCKER_GID:-1000}
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# settings <name> KEY=VALUE...: the template with these settings changed.
settings() {
    local file="$tmp/$1.env"; shift
    cp deploy/buffalo.env.example "$file"
    local kv
    for kv in "$@"; do
        sed -i.bak "/^${kv%%=*}=/d" "$file" && rm -f "$file.bak"
        printf '%s\n' "$kv" >> "$file"
    done
    printf '%s' "$file"
}

expect() { # expect <label> <settings file> "<expected nginx ports>" <compose files...>
    local label=$1 file=$2 expected=$3; shift 3
    local args=() f
    for f in "$@"; do args+=(-f "$f"); done
    CONFIG="$(BUFFALO_CONFIG="$file" BUFFALO_SECRETS_DIR=/etc/buffalo/secrets docker compose --env-file "$file" "${args[@]}" config --format json)" \
        LABEL="$label" EXPECTED="$expected" python3 - <<'PY'
import json, os, sys
cfg = json.loads(os.environ["CONFIG"])
label, expected = os.environ["LABEL"], sorted(os.environ["EXPECTED"].split())
problems = []
for name, s in cfg["services"].items():
    if name != "nginx" and s.get("ports"):
        problems.append(f"{name} publishes {s['ports']}")
actual = sorted(f"{p.get('host_ip', '0.0.0.0')}:{p['published']}->{p['target']}" for p in cfg["services"]["nginx"].get("ports", []))
if actual != expected:
    problems.append(f"nginx publishes {actual}, expected {expected}")
if problems:
    print(f"FAIL ({label}): " + "; ".join(problems))
    sys.exit(1)
print(f"OK ({label}): nginx publishes {', '.join(actual)}")
PY
}

base=(compose.yml compose.prod.yml)
direct=$(settings direct BUFFALO_EDGE=direct)
proxy=$(settings proxy BUFFALO_EDGE=proxy)
proxy_port=$(settings proxy-port BUFFALO_EDGE=proxy BUFFALO_EDGE_PORT=9123)
# A stale public bind address from direct mode must not leak into proxy mode.
proxy_stale=$(settings proxy-stale BUFFALO_EDGE=proxy BUFFALO_BIND_ADDRESS=0.0.0.0)
acme=$(settings acme BUFFALO_EDGE=direct ACME_EMAIL=ops@buffalo.test)
local_uat=$(settings local BUFFALO_EDGE=local BUFFALO_BIND_ADDRESS=127.0.0.1 BUFFALO_HTTP_PORT=19080 BUFFALO_HTTPS_PORT=19443)

expect "direct, airgapped" "$direct" "0.0.0.0:80->8080 0.0.0.0:443->8443" "${base[@]}" compose.profile-airgapped.yml
expect "direct, connected with ACME" "$acme" "0.0.0.0:80->8080 0.0.0.0:443->8443" "${base[@]}" compose.profile-connected.yml compose.acme.yml
expect "proxy, default port" "$proxy" "127.0.0.1:8090->8081" "${base[@]}" compose.profile-airgapped.yml compose.edge-proxy.yml
expect "proxy, EDGE_PORT=9123" "$proxy_port" "127.0.0.1:9123->8081" "${base[@]}" compose.profile-cloud.yml compose.edge-proxy.yml
expect "proxy, stale BUFFALO_BIND_ADDRESS=0.0.0.0" "$proxy_stale" "127.0.0.1:8090->8081" "${base[@]}" compose.profile-airgapped.yml compose.edge-proxy.yml
expect "UAT proxy with site password" "$proxy" "127.0.0.1:8090->8081" "${base[@]}" compose.profile-airgapped.yml compose.uat.yml compose.edge-proxy.yml compose.uat-basic-auth.yml
expect "UAT local" "$local_uat" "127.0.0.1:19080->8080 127.0.0.1:19443->8443" "${base[@]}" compose.profile-airgapped.yml compose.uat.yml
