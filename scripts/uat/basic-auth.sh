#!/usr/bin/env bash
#
# scripts/uat/basic-auth.sh issue: a new site-wide password for the UAT
# environment (BASIC_AUTH=1, compose.uat-basic-auth.yml). Prints the
# username and password ONCE; only the hash (SHA-512 crypt, which nginx's
# auth_basic reads) is stored, in .uat/secrets/basic_auth. Written in place:
# nginx reads the file on every request, so a running environment uses the
# new password at once. Called by make uat-up (first start) and
# make uat-credentials.
set -euo pipefail
cd "$(dirname "$0")/../.."
dir="$PWD/.uat"
[[ "${1:-}" == issue ]] || { echo "usage: $0 issue" >&2; exit 1; }
[[ -f "$dir/buffalo.env" ]] || { echo "No UAT environment (.uat/buffalo.env)." >&2; exit 1; }

user=uat
password=$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-20)
version=$(sed -n 's/^BUFFALO_VERSION=//p' "$dir/buffalo.env" | tail -n 1)

# The hash is made in the release's app image (PHP crypt): the password goes
# in on standard input, never on a command line or in the environment.
hash=$(printf '%s\n' "$password" | docker run --rm -i --network none --entrypoint php "buffalo/app:${version:-uat}" -r '
    $salt = substr(strtr(base64_encode(random_bytes(12)), "+", "."), 0, 16);
    echo crypt(rtrim((string) fgets(STDIN), "\n"), "\$6\$rounds=100000\$".$salt."\$");')
[[ "$hash" == '$6$'* ]] || { echo "Could not hash the password." >&2; exit 1; }

chmod u+w "$dir/secrets"
[[ -f "$dir/secrets/basic_auth" ]] && chmod u+w "$dir/secrets/basic_auth"
printf '%s:%s\n' "$user" "$hash" > "$dir/secrets/basic_auth"
chmod 0444 "$dir/secrets/basic_auth"
chmod 0700 "$dir/secrets"

cat <<PRINTED

  Site password (BASIC_AUTH=1), asked by the browser before the sign-in page.
  Shown ONCE and not stored (only its hash is); make uat-credentials issues a new one.

    Username: $user
    Password: $password

PRINTED
