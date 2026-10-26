#!/usr/bin/env bash
#
# scripts/uat/edge-check.sh: checks the UAT environment's current edge mode
# end to end (test tooling, not part of a release):
#
#   - signing in through the edge over https (Playwright, Chromium);
#   - live notifications arriving over wss://<host>/app/...;
#   - the login history recording the client's address, never a forged
#     X-Forwarded-For;
#   - with BASIC_AUTH=1, the site password asked before anything else
#     (EDGE_BASIC_AUTH=user:password, as printed by make uat-up).
#
# The test host name (DEMO_HOST) is mapped to this machine inside the
# browser, so no DNS is needed. In edge mode proxy a throwaway "host nginx"
# container stands in for the server's own nginx: it runs the server block
# printed by make edge-host-config (certificate from a test CA in
# .uat/edge-test/) on 127.0.0.1:${EDGE_TEST_HTTPS_PORT:-443} and proxies to
# BUFFALO's 127.0.0.1:$BUFFALO_EDGE_PORT through host.docker.internal.
#
# The expected client address is the one the outermost nginx logs for a
# probe request (on Docker Desktop, connections to published ports arrive
# from Docker's gateway; on a Linux server, from the real client).
set -euo pipefail
cd "$(dirname "$0")/../.."
dir="$PWD/.uat"
say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }
[[ -f "$dir/buffalo.env" ]] || die "No UAT environment: run make uat-up first."
[[ -f "$dir/credentials.txt" ]] || die "Save the UAT credentials table as .uat/credentials.txt (make uat-credentials prints a new one)."

setting() { sed -n "s/^$1=//p" "$dir/buffalo.env" | tail -n 1; }
dc() { docker compose --env-file "$dir/buffalo.env" "$@"; }
edge=$(setting BUFFALO_EDGE)
host=$(setting DEMO_HOST)
hostnginx=buffalo-edge-test-hostnginx
https_port=${EDGE_TEST_HTTPS_PORT:-443}

case "$edge" in
    local)
        url="https://localhost:$(setting BUFFALO_HTTPS_PORT)"
        resolve=""
        ;;
    direct)
        # EDGE_TEST_HTTPS_PORT: with tests/edge/compose.direct-test-ports.yml (8443).
        url="https://$host"
        [[ "$https_port" == 443 ]] || url="$url:$https_port"
        resolve="$host:127.0.0.1"
        ;;
    proxy)
        port=$(setting BUFFALO_EDGE_PORT)
        url="https://$host"
        [[ "$https_port" == 443 ]] || url="$url:$https_port"
        resolve="$host:127.0.0.1"
        say "Test CA and certificate for $host (.uat/edge-test/)"
        t="$dir/edge-test"
        mkdir -p "$t"
        if [[ ! -s "$t/$host.pem" ]]; then
            openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 30 -subj "/CN=BUFFALO edge test CA" \
                -keyout "$t/ca.key" -out "$t/ca.pem" 2>/dev/null
            openssl req -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -subj "/CN=$host" \
                -keyout "$t/$host.key" -out "$t/$host.csr" 2>/dev/null
            printf 'subjectAltName=DNS:%s\n' "$host" > "$t/san.ext"
            openssl x509 -req -in "$t/$host.csr" -CA "$t/ca.pem" -CAkey "$t/ca.key" -CAcreateserial -days 30 \
                -extfile "$t/san.ext" -out "$t/$host.pem" 2>/dev/null
            chmod 0644 "$t/$host.key"
        fi
        say "Host nginx stand-in from make edge-host-config (127.0.0.1:$https_port -> 127.0.0.1:$port)"
        scripts/ops/edge-host-config.sh --config "$dir/buffalo.env" \
            | sed -e "s#/etc/ssl/CHANGE-ME/$host/fullchain.pem#/certs/$host.pem#" \
                  -e "s#/etc/ssl/CHANGE-ME/$host/privkey.pem#/certs/$host.key#" \
                  -e "s#proxy_pass http://127.0.0.1:$port;#proxy_pass http://host.docker.internal:$port;#" \
            > "$t/host-nginx.conf"
        docker rm -f "$hostnginx" >/dev/null 2>&1 || true
        docker run -d --name "$hostnginx" -p "127.0.0.1:$https_port:443" --add-host host.docker.internal:host-gateway \
            -v "$t/host-nginx.conf:/etc/nginx/conf.d/buffalo.conf:ro" -v "$t:/certs:ro" \
            buffalo/patched-nginx:dev >/dev/null
        sleep 2
        docker exec "$hostnginx" nginx -t >/dev/null 2>&1 || { docker logs "$hostnginx" >&2; die "The generated server block does not load."; }
        ;;
    *) die "Unknown edge mode in .uat/buffalo.env: $edge" ;;
esac

say "Probe: $url/up (with a forged X-Forwarded-For)"
probe_host=${url#https://}; probe_host=${probe_host%%:*}
probe_port=${url##*:}; [[ "$probe_port" =~ ^[0-9]+$ ]] || probe_port=443
args=(-sk -o /dev/null -w '   HTTP %{http_code}\n' -H 'X-Forwarded-For: 203.0.113.77')
[[ -n "$resolve" ]] && args+=(--resolve "$probe_host:$probe_port:127.0.0.1")
[[ -n "${EDGE_BASIC_AUTH:-}" ]] && args+=(-u "$EDGE_BASIC_AUTH")
curl "${args[@]}" "$url/up" || true
if [[ "$edge" == proxy ]]; then
    expect_ip=$(docker logs "$hostnginx" 2>/dev/null | awk '/GET \/up/ {ip=$1} END {print ip}')
else
    expect_ip=$(dc logs --no-log-prefix nginx 2>/dev/null | awk '/GET \/up/ {ip=$1} END {print ip}')
fi
[[ -n "$expect_ip" ]] || die "The probe request was not logged by the outermost nginx."
say "Client address seen at the edge: $expect_ip"

say "Playwright edge check ($edge: $url)"
cd tests/browser
npm ci --no-audit --no-fund >/dev/null
npx playwright install chromium >/dev/null
status=0
BUFFALO_URL="$url" EDGE_RESOLVE="$resolve" EDGE_EXPECT_IP="$expect_ip" \
    BUFFALO_CREDENTIALS="$dir/credentials.txt" \
    EDGE_CMD="cd '$PWD/../..' && docker compose --env-file '$dir/buffalo.env' run --rm -T -e HOME=/tmp -e XDG_CONFIG_HOME=/tmp tools" \
    npx playwright test --config edge.config.ts "$@" || status=$?
cd ../..
[[ "$edge" == proxy ]] && docker rm -f "$hostnginx" >/dev/null
exit $status
