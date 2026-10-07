#!/bin/sh
#
# Creates the BUFFALO release signing key (Phase 9A.3). Run once, by the
# project owner, on the offline build/signing machine, NEVER in the
# repository. Prints the public line to commit as security/release-signing.pub
# and its fingerprint to publish through a separate channel (letter, signed
# email, in person) so installers can confirm it.
#
# Usage: scripts/ops/release-key.sh <directory for the private key>
#
# Keep the private key encrypted (you are asked for a passphrase) and backed
# up like the other escrowed keys. Losing it means new releases cannot be
# signed with the same key: publish a new public key and re-establish trust.
set -eu

dir=${1:?usage: $0 <directory for the private key>}
case "$dir" in
    *buffalo*/security*|*/.git*) echo "Refusing: keep the private key outside the repository." >&2; exit 1 ;;
esac
mkdir -p "$dir"
chmod 0700 "$dir"
key="$dir/buffalo-release"
[ -e "$key" ] && { echo "$key already exists; not overwriting." >&2; exit 1; }

ssh-keygen -t ed25519 -C "buffalo-release $(date -u +%Y-%m-%d)" -f "$key"
echo
echo "Commit this line as security/release-signing.pub (replacing the test key):"
printf 'buffalo-release namespaces="buffalo-release" %s\n' "$(cat "$key.pub")"
echo
echo "Publish this fingerprint separately:"
ssh-keygen -lf "$key.pub"
