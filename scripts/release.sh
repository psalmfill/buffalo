#!/usr/bin/env bash
#
# Builds a signed BUFFALO release (Phase 9A.3). Run in CI or on the build
# machine:
#
#   make release VERSION=1.2.3 [RELEASE_KEY=/path/to/buffalo-release] [SINCE=v1.2.2]
#
# Produces dist/buffalo-<version>/:
#   buffalo-<v>-images.tar.zst   every image (docker save, linux/amd64)
#   buffalo-<v>-bundle.tar.zst   compose files, profiles, scripts, docs, VERSION,
#                                images.lock (image IDs), release notes, public key
#   install.sh                   the installer (also inside the bundle)
#   RELEASE-NOTES.md
#   SHA256SUMS, SHA256SUMS.sig   checksums, signed with `ssh-keygen -Y sign
#                                -n buffalo-release` (the key never enters the repo)
#
# Options (environment): RELEASE_KEY (private key, required), SINCE (git ref
# of the previous release for the notes; default: the last tag),
# PLATFORM (default linux/amd64), REUSE_IMAGES=1 (skip the image build if
# buffalo/*:<version> already exist), SKIP_SCAN=1 (test releases only: no
# Trivy gate; the go-live check then fails).
set -euo pipefail
cd "$(dirname "$0")/.."

version=${VERSION:?set VERSION, e.g. make release VERSION=1.2.3}
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "VERSION must be semantic (x.y.z)." >&2; exit 1; }
key=${RELEASE_KEY:?set RELEASE_KEY to the release signing private key (outside the repository)}
[[ -f "$key" ]] || { echo "No private key at $key." >&2; exit 1; }
case "$(cd "$(dirname "$key")" && pwd)" in "$PWD"*) echo "Refusing: the signing key must not be inside the repository." >&2; exit 1 ;; esac
platform=${PLATFORM:-linux/amd64}
since=${SINCE:-$(git describe --tags --abbrev=0 2>/dev/null || git rev-list --max-parents=0 HEAD)}
command -v zstd >/dev/null || { echo "zstd is required." >&2; exit 1; }

step() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
out="dist/buffalo-$version"
rm -rf "$out"; mkdir -p "$out"

images=(app nginx postgres clamav valkey restic patched-gotenberg patched-postgis patched-nginx patched-clamav)

if [[ "${REUSE_IMAGES:-0}" == 1 ]]; then
    step "Reusing existing buffalo/*:$version images"
else
    step "Building images ($platform)"
    scripts/build-release-images.sh "$version" --platform "$platform" --no-cache
fi

embedded=$(sed -n "/^# >>> release-signing.pub/,/^# <<< release-signing.pub/p" scripts/ops/install.sh | sed -e '1d' -e '$d' -e "s/^ALLOWED_SIGNERS='//" -e "s/'\$//")
[[ "$embedded" == "$(grep -v '^#' security/release-signing.pub | sed '/^$/d')" ]] \
    || { echo "scripts/ops/install.sh embeds a different key from security/release-signing.pub; update it." >&2; exit 1; }

step "Security scan of the release images (Trivy gate)"
if [[ "${SKIP_SCAN:-0}" == 1 ]]; then
    # Test releases only: the go-live check fails on a skipped scan.
    mkdir -p build/security
    printf '{"version":"%s","result":"skipped","scanned_at":"%s","commit":"%s","scanner":"none (SKIP_SCAN=1)","images":[]}\n' \
        "$version" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(git rev-parse --short HEAD)" > build/security/release-scan.json
    echo "Scan skipped (SKIP_SCAN=1): this release will not pass the go-live check."
else
    scripts/security-scan.sh trivy-release "$version"
fi

step "Image manifest (images.lock: image IDs, verified at install)"
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
bundle="$stage/buffalo-$version"
mkdir -p "$bundle"
for name in "${images[@]}"; do
    ref="buffalo/$name:$version"
    id=$(docker image inspect --format '{{.Id}}' "$ref")
    arch=$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$ref")
    printf '%s %s %s\n' "$name" "$id" "$arch"
done > "$bundle/images.lock"
cat "$bundle/images.lock"

step "Saving images"
refs=(); for name in "${images[@]}"; do refs+=("buffalo/$name:$version"); done
docker save "${refs[@]}" | zstd -q -T0 -19 -o "$out/buffalo-$version-images.tar.zst"

step "Release notes"
{
    echo "# BUFFALO $version"
    echo
    echo "Released $(date -u +%Y-%m-%d) from commit $(git rev-parse --short HEAD) (changes since \`$since\`)."
    for type in feat fix build ci docs; do
        lines=$(git log --format='%s' "$since..HEAD" | grep -E "^$type(\(|:)" || true)
        [[ -n "$lines" ]] || continue
        case $type in feat) title=Features;; fix) title=Fixes;; build) title=Build;; ci) title=CI;; docs) title=Documentation;; esac
        echo; echo "## $title"; echo
        echo "$lines" | sed 's/^/- /'
    done
    echo; echo "## Database migrations"; echo
    migrations=$(git diff --name-only --diff-filter=A "$since..HEAD" -- database/migrations | xargs -n1 basename 2>/dev/null || true)
    if [[ -n "$migrations" ]]; then echo "$migrations" | sed 's/^/- /'; else echo "None."; fi
    echo; echo "## Images"; echo
    echo '```'; cat "$bundle/images.lock"; echo '```'
} > "$out/RELEASE-NOTES.md"

step "Bundle"
cp compose.yml compose.prod.yml compose.profile-*.yml compose.egress-*.yml compose.acme.yml "$bundle/"
mkdir -p "$bundle/scripts" "$bundle/docs" "$bundle/security" "$bundle/deploy" "$bundle/docker/drill"
cp -R scripts/ops scripts/restore "$bundle/scripts/"
cp scripts/clamav-import-signatures.sh "$bundle/scripts/"
cp -R docs/operations docs/security "$bundle/docs/"
cp deploy/buffalo.env.example "$bundle/deploy/"
cp docker/drill/compose.drill.yml "$bundle/docker/drill/"
cp security/release-signing.pub "$bundle/security/"
cp "$out/RELEASE-NOTES.md" "$bundle/"
cp build/security/release-scan.json "$bundle/security-scan.json"
echo "$version" > "$bundle/VERSION"
tar -C "$stage" -cf - "buffalo-$version" | zstd -q -T0 -19 -o "$out/buffalo-$version-bundle.tar.zst"
cp scripts/ops/install.sh "$out/install.sh"

step "Checksums and signature"
( cd "$out" && sha256sum "buffalo-$version-images.tar.zst" "buffalo-$version-bundle.tar.zst" install.sh RELEASE-NOTES.md > SHA256SUMS ) 2>/dev/null \
    || ( cd "$out" && shasum -a 256 "buffalo-$version-images.tar.zst" "buffalo-$version-bundle.tar.zst" install.sh RELEASE-NOTES.md > SHA256SUMS )
ssh-keygen -q -Y sign -f "$key" -n buffalo-release "$out/SHA256SUMS"
mv "$out/SHA256SUMS.sig" "$out/SHA256SUMS.sig" 2>/dev/null || true

step "Self-check"
principal=$(grep -v '^#' security/release-signing.pub | awk 'NF{print $1; exit}')
ssh-keygen -q -Y verify -f security/release-signing.pub -I "$principal" -n buffalo-release -s "$out/SHA256SUMS.sig" < "$out/SHA256SUMS" \
    || { echo "The signature does not verify against security/release-signing.pub: wrong key?" >&2; exit 1; }
ls -la "$out"
step "Release $version ready in $out (signed by '$principal')."
