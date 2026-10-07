#!/usr/bin/env bash
#
# BUFFALO security scanning (Phase 8B.7). CI only: nothing here is part of
# the runtime stack. Needs Docker and network access (vulnerability
# databases, image pulls).
#
#   scripts/security-scan.sh trivy   # prod image + pinned third-party images
#   scripts/security-scan.sh zap     # OWASP ZAP against the running stack
#
# Reports go to build/security/. Both fail on high or critical findings;
# Trivy only on fixable ones not listed in security/vulnerability-exceptions.yaml
# (dated, at most 60 days), ZAP except for the accepted alerts listed in
# docs/security/zap-accepted.md (scripts/zap/accepted.txt).

set -euo pipefail
cd "$(dirname "$0")/.."

TRIVY_IMAGE="aquasec/trivy:0.75.0@sha256:af6acf9a6b85dfe389a1941505c0ce9efef52a4719635e1a962f022a3d855daa"
ZAP_IMAGE="zaproxy/zap-stable:2.17.0@sha256:781a2bdaea47324e7bab583e2263f21d257b0aee61ed51521a5be45f5f5081ef"
REPORTS="build/security"
mkdir -p "$REPORTS"

step() {
    printf '\n\033[1;34m==> %s\033[0m\n' "$1"
}

# Images scanned: BUFFALO's production image, the BUFFALO-patched
# third-party images (docker/patched/, Phase 9A.2), and BUFFALO's Valkey
# and restic images (upstream plus Alpine updates and configuration).
scan_targets() {
    echo buffalo-app:prod
    for name in postgis nginx clamav gotenberg; do echo "buffalo/patched-${name}:scan"; done
    echo buffalo/valkey:scan
    echo buffalo/restic:scan
}

trivy() {
    docker run --rm \
        -v /var/run/docker.sock:/var/run/docker.sock \
        -v buffalo_trivy_cache:/root/.cache/trivy \
        -v "$PWD/$REPORTS":/reports \
        -v "$PWD/security/vulnerability-exceptions.yaml":/exceptions.yaml:ro \
        "$TRIVY_IMAGE" "$@"
}

scan_trivy() {
    step "Exceptions file"
    python3 scripts/security/check-exceptions.py

    step "Build the production and patched images (linux/amd64, fresh updates)"
    docker build --platform linux/amd64 -f docker/php/Dockerfile --target prod -t buffalo-app:prod . >/dev/null
    scripts/build-patched-images.sh scan --platform linux/amd64 --no-cache
    docker build --platform linux/amd64 --no-cache --pull -t buffalo/valkey:scan docker/valkey >/dev/null
    docker build --platform linux/amd64 --no-cache --pull -t buffalo/restic:scan docker/backup >/dev/null
    rm -f "$REPORTS"/trivy-*.json "$REPORTS"/trivy-*.txt

    local failed=0 image name
    for image in $(scan_targets); do
        name=$(echo "$image" | sed 's/@.*//; s#[/:]#_#g')
        step "Trivy: $image"
        docker pull -q --platform linux/amd64 "$image" >/dev/null 2>&1 || true
        # Full report (all severities, fixed or not) as an artifact.
        trivy image --quiet --platform linux/amd64 --scanners vuln --format json --output "/reports/trivy-$name.json" "$image"
        # Gate: fixable high and critical findings without a dated exception.
        if ! trivy image --quiet --platform linux/amd64 --scanners vuln --severity HIGH,CRITICAL --ignore-unfixed \
            --ignorefile /exceptions.yaml --exit-code 1 --format table "$image" | tee "$REPORTS/trivy-$name.txt"; then
            failed=1
        fi
    done

    if [[ $failed -ne 0 ]]; then
        echo "Trivy found fixable high or critical vulnerabilities without an exception (see $REPORTS)." >&2
        exit 1
    fi
}

scan_zap() {
    local target="${ZAP_TARGET:-http://nginx:8080}"
    local network="${ZAP_NETWORK:-buffalo_frontend}"

    step "Create a scan session (seeded test user)"
    local cookie
    cookie=$(docker compose exec -T app php scripts/zap/session.php)

    step "OWASP ZAP: authenticated spider, passive scan, limited active scan"
    # The zap user (uid 1000) writes reports into the mounted directory.
    chmod a+w "$REPORTS"
    docker run --rm --network "$network" \
        -e ZAP_TARGET="$target" -e ZAP_COOKIE="$cookie" \
        -v "$PWD/scripts/zap":/zap/wrk/plan:ro \
        -v "$PWD/$REPORTS":/zap/wrk/reports \
        "$ZAP_IMAGE" zap.sh -cmd -autorun /zap/wrk/plan/plan.yaml || true

    step "Check ZAP findings"
    python3 scripts/zap/check.py "$REPORTS/zap-report.json" scripts/zap/accepted.txt
}

# The images of a release (Phase 9B.6), as built by build-release-images.sh,
# with the same gate. Writes build/security/release-scan.json, which
# make release puts in the signed bundle and install.sh / upgrade.sh record
# for the go-live check.
scan_release() {
    local version=${1:?usage: $0 trivy-release <version>}
    step "Exceptions file"
    python3 scripts/security/check-exceptions.py
    rm -f "$REPORTS"/trivy-*.json "$REPORTS"/trivy-*.txt

    local failed=0 name image summary=""
    for name in app nginx postgres clamav valkey restic patched-gotenberg; do
        image="buffalo/$name:$version"
        step "Trivy: $image"
        trivy image --quiet --scanners vuln --format json --output "/reports/trivy-$name.json" "$image"
        if trivy image --quiet --scanners vuln --severity HIGH,CRITICAL --ignore-unfixed \
            --ignorefile /exceptions.yaml --exit-code 1 --format table "$image" | tee "$REPORTS/trivy-$name.txt"; then
            summary="$summary{\"image\":\"$name\",\"gate\":\"passed\"},"
        else
            summary="$summary{\"image\":\"$name\",\"gate\":\"failed\"},"
            failed=1
        fi
    done

    local result=passed
    [[ $failed -eq 0 ]] || result=failed
    printf '{"version":"%s","result":"%s","scanned_at":"%s","commit":"%s","scanner":"%s","images":[%s]}\n' \
        "$version" "$result" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(git rev-parse --short HEAD)" "trivy 0.75.0, HIGH/CRITICAL fixable, exceptions file" \
        "${summary%,}" > "$REPORTS/release-scan.json"
    cat "$REPORTS/release-scan.json"

    if [[ $failed -ne 0 ]]; then
        echo "Trivy found fixable high or critical vulnerabilities without an exception (see $REPORTS)." >&2
        exit 1
    fi
}

case "${1:-}" in
    trivy) scan_trivy ;;
    trivy-release) scan_release "${2:-}" ;;
    zap) scan_zap ;;
    *) echo "Usage: $0 trivy|trivy-release <version>|zap" >&2; exit 2 ;;
esac
