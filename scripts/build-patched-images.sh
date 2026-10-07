#!/usr/bin/env bash
#
# Builds the BUFFALO-patched third-party images (Phase 9A.2): the
# digest-pinned upstream images with OS security updates (docker/patched/).
#
#   scripts/build-patched-images.sh <tag> [--platform linux/amd64] [--no-cache]
#
# Development uses the tag `dev` (`make images`); releases and the weekly
# images workflow use the release version and --no-cache, so updates are
# always fetched fresh. PostGIS is linux/amd64 only.
set -euo pipefail
cd "$(dirname "$0")/.."

tag=${1:?usage: $0 <tag> [--platform P] [--no-cache]}
shift
platform=""
extra=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --platform) platform=$2; shift 2 ;;
        --no-cache) extra+=(--no-cache --pull); shift ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done

date=$(date -u +%Y-%m-%dT%H:%M:%SZ)
for name in postgis nginx clamav gotenberg; do
    p=${platform}
    [[ "$name" == postgis ]] && p=linux/amd64
    echo "==> buffalo/patched-${name}:${tag}"
    docker build ${p:+--platform "$p"} "${extra[@]+"${extra[@]}"}" --build-arg BUILD_DATE="$date" \
        -t "buffalo/patched-${name}:${tag}" "docker/patched/${name}" >/dev/null
done
