#!/usr/bin/env bash
#
# Fresh-host installation test (Phase 9A.6). Installs a release with
# install.sh into a throwaway location (its own project, settings, secrets,
# self-signed certificate), then proves from inside every container which
# ones can reach the internet, and checks TLS and the redirect.
#
#   scripts/ci/release-install-test.sh <release dir> --profile airgapped|connected [options]
#
#   --work DIR          Scratch directory (default: a new temporary directory)
#   --project NAME      Compose project (default buffalo-e2e)
#   --overlays          connected: also --mail-egress and --clamav-online
#   --keep              Leave the installation running afterwards
#   --teardown          Only remove a previous test installation (with --work/--project)
#
# Expected reachability: airgapped none; connected nginx (plus horizon and
# clamav with --overlays). Any other container that can connect out fails
# the test. The frontend egress firewall rule must be active (applied by
# install.sh as root on Linux; apply it yourself on Docker Desktop).
set -euo pipefail
cd "$(dirname "$0")/../.."

release=""; profile=""; work=""; project=buffalo-e2e; overlays=false; keep=false; teardown_only=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --profile) profile=$2; shift 2 ;;
        --work) work=$2; shift 2 ;;
        --project) project=$2; shift 2 ;;
        --overlays) overlays=true; shift ;;
        --keep) keep=true; shift ;;
        --teardown) teardown_only=true; shift ;;
        -h|--help) sed -n '2,/^set -euo/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
        *) release=$1; shift ;;
    esac
done
work=${work:-$(mktemp -d)}
mkdir -p "$work"; work=$(cd "$work" && pwd)
say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mFAIL:\033[0m %s\n' "$*" >&2; exit 1; }
dc() { (cd "$work/opt/current" && docker compose --env-file "$work/buffalo.env" "$@"); }
teardown() { [[ -f "$work/buffalo.env" && -e "$work/opt/current" ]] && dc --profile tools down -v --remove-orphans >/dev/null 2>&1 || true; }

if $teardown_only; then teardown; say "Removed $project"; exit 0; fi
[[ -n "$release" && -d "$release" ]] || fail "Give the release directory."
[[ "$profile" == airgapped || "$profile" == connected ]] || fail "--profile airgapped|connected"
release=$(cd "$release" && pwd)
version=$(cd "$release" && ls buffalo-*-bundle.tar.zst | sed 's/^buffalo-//; s/-bundle.tar.zst$//')

say "Preparing $work (project $project, release $version, profile $profile)"
teardown
rm -rf "$work/secrets" "$work/opt" "$work/tls"
mkdir -p "$work/tls"
sh scripts/ops/generate-secrets.sh --dir "$work/secrets" >/dev/null
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 90 -subj /CN=localhost \
    -addext "subjectAltName=DNS:localhost" -keyout "$work/tls.key" -out "$work/tls/fullchain.pem" 2>/dev/null
chmod 0644 "$work/tls/fullchain.pem"
chmod u+w "$work/secrets"; mv "$work/tls.key" "$work/secrets/tls_key"; chmod 0444 "$work/secrets/tls_key"; chmod 0700 "$work/secrets"
sed -e "s#^COMPOSE_PROJECT_NAME=.*#COMPOSE_PROJECT_NAME=$project#" \
    -e "s#^BUFFALO_SERVER_NAME=.*#BUFFALO_SERVER_NAME=localhost#" \
    -e "s#^APP_URL=.*#APP_URL=https://localhost:18443#" \
    -e "s#^BUFFALO_TLS_CERTIFICATE_FILE=.*#BUFFALO_TLS_CERTIFICATE_FILE=$work/tls/fullchain.pem#" \
    -e "s#^BUFFALO_BIND_ADDRESS=.*#BUFFALO_BIND_ADDRESS=127.0.0.1#" \
    -e "s#^BUFFALO_HTTP_PORT=.*#BUFFALO_HTTP_PORT=18080#" \
    -e "s#^BUFFALO_HTTPS_PORT=.*#BUFFALO_HTTPS_PORT=18443#" \
    deploy/buffalo.env.example > "$work/buffalo.env"

say "Installing with install.sh"
args=(--profile "$profile" --release-dir "$release" --config "$work/buffalo.env" --secrets-dir "$work/secrets"
      --install-dir "$work/opt" --allow-test-signature --accept-low-memory --yes)
$overlays && args+=(--mail-egress --clamav-online)
[[ "$(uname -s)" == Linux && "$(id -u)" == 0 ]] || args+=(--skip-firewall)
sh "$release/install.sh" "${args[@]}"

say "Outbound connections from inside each container (TCP 1.1.1.1:443)"
expected=""
[[ "$profile" == connected ]] && expected="nginx"
$overlays && expected="$expected horizon clamav"
probe="buffalo/valkey:$version"
problems=0
for id in $(docker ps -q --filter "label=com.docker.compose.project=$project"); do
    name=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.service"}}' "$id")
    if docker run --rm --network "container:$id" --entrypoint sh "$probe" -c 'nc -z -w 4 1.1.1.1 443' >/dev/null 2>&1; then got=reachable; else got=blocked; fi
    want=blocked; [[ " $expected " == *" $name "* ]] && want=reachable
    if [[ "$got" == "$want" ]]; then printf '   ok    %-11s %s\n' "$name" "$got"; else printf '   FAIL  %-11s %s (expected %s)\n' "$name" "$got" "$want"; problems=$((problems + 1)); fi
done
[[ $problems -eq 0 ]] || fail "$problems container(s) with unexpected internet access."

say "HTTPS and redirect"
code=$(curl -sk -o /dev/null -w '%{http_code}' https://localhost:18443/login); [[ "$code" == 200 ]] || fail "HTTPS /login answered $code"
code=$(curl -s -o /dev/null -w '%{http_code}' http://localhost:18080/login); [[ "$code" == 301 ]] || fail "HTTP did not redirect ($code)"
for proto in tls1 tls1_1; do
    if echo | openssl s_client -connect localhost:18443 "-$proto" >/dev/null 2>&1; then fail "$proto accepted"; fi
done
echo "   200 over HTTPS, HTTP redirects, TLS 1.0/1.1 refused"

$keep || teardown
say "PASS: $profile install of $version"
