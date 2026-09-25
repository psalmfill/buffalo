#!/usr/bin/env bash
#
# Fetch the BUFFALO offline basemap (Nigeria) into the map_tiles volume.
#
# THIS SCRIPT IS THE ONLY PART OF BUFFALO ALLOWED TO USE THE INTERNET.
# It is run once, manually, at setup time (`make tiles url=...`). The running
# application never makes outbound requests: nginx serves the resulting file
# from the map_tiles Docker volume at /tiles/nigeria.pmtiles.
#
# For an air-gapped server, run this on a connected machine, then copy
# nigeria.pmtiles into the target's map_tiles volume, e.g.:
#   docker run --rm -v buffalo_map_tiles:/tiles -v "$PWD":/src alpine:3.24.2 \
#       cp /src/nigeria.pmtiles /tiles/nigeria.pmtiles
#
# It uses the official pmtiles CLI Docker image to extract the Nigeria
# bounding box (max zoom 14) from a Protomaps daily basemap build over HTTP
# range requests, so only the needed part of the planet file is downloaded.
#
# Map data © OpenStreetMap contributors (ODbL); basemap by Protomaps.

set -euo pipefail

PMTILES_IMAGE="protomaps/go-pmtiles:v1.31.2"
# west,south,east,north
BBOX="2.6,4.2,14.7,13.9"
MAX_ZOOM=14
VOLUME="buffalo_map_tiles"
OUTPUT="nigeria.pmtiles"

usage() {
    cat <<EOF
Usage: make tiles url=<protomaps-build-url>
   or: $0 <protomaps-build-url>

Downloads the Nigeria extract (bbox ${BBOX}, max zoom ${MAX_ZOOM}) from a
Protomaps basemap build into the Docker volume "${VOLUME}" as ${OUTPUT}.

Build file names change daily. Find a current build at:
    https://maps.protomaps.com/builds/
Builds are named by date, for example:
    https://build.protomaps.com/20260920.pmtiles

Example:
    make tiles url=https://build.protomaps.com/20260920.pmtiles
EOF
}

if [[ $# -lt 1 || -z "${1}" || "${1}" == "-h" || "${1}" == "--help" ]]; then
    usage
    exit 1
fi

SOURCE_URL="$1"

if [[ ! "${SOURCE_URL}" =~ ^https?://.+\.pmtiles$ ]]; then
    echo "Error: expected an http(s) URL ending in .pmtiles, got: ${SOURCE_URL}" >&2
    echo >&2
    usage >&2
    exit 1
fi

# Create the volume with Compose labels so `docker compose` treats it as its
# own map_tiles volume (it normally exists already after `make setup`).
if ! docker volume inspect "${VOLUME}" > /dev/null 2>&1; then
    docker volume create \
        --label com.docker.compose.project=buffalo \
        --label com.docker.compose.volume=map_tiles \
        "${VOLUME}" > /dev/null
fi

echo "Extracting Nigeria (bbox ${BBOX}, zoom 0-${MAX_ZOOM}) from:"
echo "    ${SOURCE_URL}"

# Write to a temporary name and rename, so nginx never serves a partial file.
docker run --rm \
    -v "${VOLUME}:/tiles" \
    "${PMTILES_IMAGE}" \
    extract "${SOURCE_URL}" "/tiles/${OUTPUT}.partial" \
    --bbox="${BBOX}" \
    --maxzoom="${MAX_ZOOM}"

docker run --rm \
    -v "${VOLUME}:/tiles" \
    --entrypoint /bin/sh \
    alpine:3.24.2 \
    -c "mv /tiles/${OUTPUT}.partial /tiles/${OUTPUT} && chmod 0644 /tiles/${OUTPUT} && ls -lh /tiles/${OUTPUT}"

echo "Done. The map at http://localhost:8000/dashboard now uses the offline tiles."
