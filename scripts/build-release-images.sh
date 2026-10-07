#!/usr/bin/env bash
#
# Builds every image of a BUFFALO release (Phase 9A.3), in dependency order:
# the patched third-party images, the application (prod target), nginx
# (with the application's public/), and the configuration layers.
#
#   scripts/build-release-images.sh <version> [--platform linux/amd64] [--no-cache]
#
# Images are tagged buffalo/<name>:<version>. Production images run as UID
# and GID 1000 (the application user and the restic backups).
set -euo pipefail
cd "$(dirname "$0")/.."

version=${1:?usage: $0 <version> [--platform P] [--no-cache]}
shift
platform=""
cache=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --platform) platform=$2; shift 2 ;;
        --no-cache) cache=(--no-cache); shift ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done
plat=(${platform:+--platform "$platform"})

step() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

step "Patched third-party images"
scripts/build-patched-images.sh "$version" ${platform:+--platform "$platform"} ${cache[@]+--no-cache}

build() { # build <image> <args...>
    step "buffalo/$1:$version"
    shift
    docker build "${plat[@]+"${plat[@]}"}" "${cache[@]+"${cache[@]}"}" --build-arg UID=1000 --build-arg GID=1000 "$@" >/dev/null
}

build app -f docker/php/Dockerfile --target prod -t "buffalo/app:$version" --build-arg BUFFALO_VERSION="$version" .
build nginx -f docker/nginx/Dockerfile --target prod -t "buffalo/nginx:$version" \
    --build-arg BASE_IMAGE="buffalo/patched-nginx:$version" --build-arg APP_IMAGE="buffalo/app:$version" .
# PostGIS is linux/amd64 only.
step "buffalo/postgres:$version"
docker build --platform linux/amd64 "${cache[@]+"${cache[@]}"}" --build-arg UID=1000 --build-arg GID=1000 \
    --build-arg BASE_IMAGE="buffalo/patched-postgis:$version" -t "buffalo/postgres:$version" docker/postgres >/dev/null
build clamav --build-arg BASE_IMAGE="buffalo/patched-clamav:$version" -t "buffalo/clamav:$version" docker/clamav
build valkey -t "buffalo/valkey:$version" docker/valkey
build restic -t "buffalo/restic:$version" docker/backup

step "Images"
docker image ls --format '{{.Repository}}:{{.Tag}}  {{.Size}}' | grep -E "^buffalo/[a-z-]+:${version}\b" | sort
