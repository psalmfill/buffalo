#!/bin/sh
#
# acme-prepare.sh <compose project name>: before the first ACME issuance
# (compose.acme.yml), give nginx something to start with. It puts a
# temporary self-signed certificate (7 days, CN=placeholder) into the
# project's `letsencrypt` volume, unless a certificate is already there,
# and hands it and the `acme_webroot` volume (where certbot writes the
# HTTP-01 challenges nginx serves) to uid 101 (nginx and certbot); Docker
# creates both root-owned. certbot then replaces the placeholder within
# seconds of nginx starting. Used by install.sh --acme and make uat-up
# TLS=acme.
set -eu
project=${1:?usage: acme-prepare.sh <compose project name>}

docker run --rm --network none --user 0 --entrypoint sh -v "${project}_letsencrypt":/etc/letsencrypt -v "${project}_acme_webroot":/var/www/acme \
    certbot/certbot:v5.8.0@sha256:f70ad0adbb7e117f0fe42a63c553f28ea451edabc0148757b6efcd9735acaa20 -c \
    'mkdir -p /etc/letsencrypt/buffalo; [ -s /etc/letsencrypt/buffalo/fullchain.pem ] || openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 7 -subj /CN=placeholder -keyout /etc/letsencrypt/buffalo/privkey.pem -out /etc/letsencrypt/buffalo/fullchain.pem 2>/dev/null; chown -R 101:101 /etc/letsencrypt /var/www/acme'
