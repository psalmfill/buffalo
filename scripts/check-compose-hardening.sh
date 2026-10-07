#!/usr/bin/env bash
#
# Container hardening check (Phase 8C.5). Fails if any service in the base
# compose.yml lacks: an image digest pin (or, for built images, digest-pinned
# Dockerfile base images), no-new-privileges, cap_drop ALL, a health check,
# or memory and CPU limits. Also reports added capabilities, root users and
# writable root filesystems.
#
#   scripts/check-compose-hardening.sh            # static check (CI)
#   scripts/check-compose-hardening.sh --running  # also inspect the running containers
#
# Needs Docker (docker compose config) and Python 3.
set -euo pipefail
cd "$(dirname "$0")/.."

running=false
[[ "${1:-}" == "--running" ]] && running=true

config=$(DOCKER_UID=1000 DOCKER_GID=1000 docker compose -f compose.yml config --format json)

CONFIG="$config" python3 - <<'PY'
import json, os, re, sys

cfg = json.loads(os.environ["CONFIG"])
problems, notes = [], []

def dockerfile_pinned(context, dockerfile):
    """Every FROM is pinned by digest, a local stage, or (through an ARG
    default) a BUFFALO image built from another checked Dockerfile."""
    path = os.path.join(context, dockerfile or "Dockerfile")
    stages, args = set(), {}
    ok = True
    for line in open(path):
        a = re.match(r"\s*ARG\s+(\w+)=(\S+)", line)
        if a:
            args[a.group(1)] = a.group(2)
            continue
        m = re.match(r"\s*FROM\s+(\S+)(?:\s+AS\s+(\S+))?", line, re.I)
        if not m:
            continue
        image, alias = m.group(1), m.group(2)
        resolved = re.sub(r"\$\{(\w+)\}", lambda v: args.get(v.group(1), v.group(0)), image)
        if resolved in stages or "@sha256:" in resolved or resolved.startswith("buffalo/"):
            pass
        else:
            problems.append(f"{path}: base image {image} is not pinned by digest")
            ok = False
        if alias:
            stages.add(alias)
    return ok

# The patched third-party images are always checked (Phase 9A.2).
for patched in sorted(os.listdir("docker/patched")):
    dockerfile_pinned(os.path.join("docker/patched", patched), "Dockerfile")

# Images built by a service here (their Dockerfiles are checked instead).
built = {s.get("image") for s in cfg["services"].values() if "build" in s}

for name, s in sorted(cfg["services"].items()):
    image = s.get("image", "")
    if "build" in s:
        dockerfile_pinned(s["build"]["context"], s["build"].get("dockerfile"))
    elif image not in built and "@sha256:" not in image:
        problems.append(f"{name}: image {image} is not pinned by digest")
    if "no-new-privileges:true" not in s.get("security_opt", []):
        problems.append(f"{name}: missing security_opt no-new-privileges:true")
    if "ALL" not in s.get("cap_drop", []):
        problems.append(f"{name}: missing cap_drop ALL")
    if s.get("cap_add"):
        notes.append(f"{name}: adds capabilities {s['cap_add']}")
    hc = s.get("healthcheck")
    if not hc or hc.get("disable"):
        problems.append(f"{name}: no health check")
    limits = s.get("deploy", {}).get("resources", {}).get("limits", {})
    if not limits.get("memory") or not limits.get("cpus"):
        problems.append(f"{name}: missing memory or CPU limits")
    if not s.get("read_only"):
        notes.append(f"{name}: root filesystem is writable")
    if not s.get("user") and image not in built:
        notes.append(f"{name}: no user set (the image's default user is used)")

for n in notes:
    print(f"note: {n}")
if problems:
    print("\n".join(f"FAIL: {p}" for p in problems))
    sys.exit(1)
print(f"OK: {len(cfg['services'])} services pass the static hardening checks.")
PY

if $running; then
    status=0
    for id in $(docker compose ps -q); do
        name=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.service"}}' "$id")
        [[ "$name" == "mailpit" || "$name" == "vite" ]] && continue   # development only
        read -r readonly user <<<"$(docker inspect -f '{{.HostConfig.ReadonlyRootfs}} {{.Config.User}}' "$id")"
        caps=$(docker exec "$id" sh -c 'grep -E "^(CapEff|NoNewPrivs)" /proc/1/status' 2>/dev/null | tr -s '\t ' ' ' | tr '\n' ' ')
        uid=$(docker exec "$id" sh -c 'id -u' 2>/dev/null || echo '?')
        printf '%-12s uid=%-5s readonly=%-5s %s\n' "$name" "$uid" "$readonly" "$caps"
        if [[ "$caps" != *"CapEff: 0000000000000000"* || "$caps" != *"NoNewPrivs: 1"* || "$uid" == "0" ]]; then
            echo "FAIL: $name runs with capabilities, without no-new-privileges, or as root" >&2
            status=1
        fi
    done
    exit $status
fi
