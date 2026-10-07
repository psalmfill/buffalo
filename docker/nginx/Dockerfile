# syntax=docker/dockerfile:1
# BUFFALO nginx (Phase 9A): the BUFFALO-patched nginx image with the
# configuration baked in. Build context: the repository root.
#
#   base - configuration only (development: compose.override.yml mounts the
#          source tree for public/).
#   prod - plus the application's public/ directory (index.php and the built
#          assets) from the prod application image (APP_IMAGE), the TLS
#          template and the maintenance page.

ARG BASE_IMAGE=buffalo/patched-nginx:dev
ARG APP_IMAGE=buffalo/app:dev

FROM ${APP_IMAGE} AS app

FROM ${BASE_IMAGE} AS base
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/buffalo-app.conf /etc/nginx/buffalo/app.conf
COPY docker/nginx/maintenance.html /usr/share/nginx/maintenance/maintenance.html

FROM base AS prod
RUN rm -f /etc/nginx/conf.d/default.conf
COPY docker/nginx/templates/ /etc/nginx/templates/
# The template step (20-envsubst-on-templates.sh) writes to /tmp, an empty
# tmpfs at start: create its output directory first.
RUN printf '#!/bin/sh\nmkdir -p /tmp/nginx-conf.d\n' > /docker-entrypoint.d/15-buffalo-conf-dir.sh \
    && chmod 0755 /docker-entrypoint.d/15-buffalo-conf-dir.sh
COPY --chmod=0755 docker/nginx/cert-reload.sh /docker-entrypoint.d/40-buffalo-cert-reload.sh
COPY --from=app /var/www/html/public /var/www/html/public
ENV NGINX_ENVSUBST_OUTPUT_DIR=/tmp/nginx-conf.d \
    NGINX_OCSP_STAPLING=off
