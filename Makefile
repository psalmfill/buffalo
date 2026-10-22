# BUFFALO development tasks. Everything runs inside Docker containers; the
# host only needs Docker, Docker Compose and Make.

# Containers run as the host user so files they create are owned by you.
export DOCKER_UID ?= $(shell id -u)
export DOCKER_GID ?= $(shell id -g)

COMPOSE := docker compose
# PHP tools in a one-off app container (starts postgres/valkey if needed).
PHP     := $(COMPOSE) run --rm app
# PHP tools that need no services.
PHP_ND  := $(COMPOSE) run --rm --no-deps app
# Node/npm in a one-off vite container.
NODE    := $(COMPOSE) run --rm --no-deps vite

.DEFAULT_GOAL := help

.PHONY: help setup up down shell artisan test lint fix fresh logs tiles release uat-up uat-down uat-credentials browser-check images restore-drill security-scan clamav-import-signatures wayfinder ci

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

setup: ## First-time setup: .env, images, dependencies, app key, migrations, seeders
	@test -f .env || (cp .env.example .env && echo "Created .env from .env.example")
	$(MAKE) images
	$(COMPOSE) build
	$(PHP_ND) composer install --no-interaction
	$(NODE) npm ci --no-audit --no-fund
	@grep -Eq '^APP_KEY=.+' .env || $(PHP_ND) php artisan key:generate --no-interaction
	@grep -Eq '^CIPHERSWEET_KEY=.+' .env || $(PHP_ND) php artisan buffalo:encryption-key --no-interaction
	$(MAKE) wayfinder
	$(COMPOSE) up -d --wait
	$(COMPOSE) exec app php artisan buffalo:db:provision-roles --database=buffalo --database=buffalo_test
	$(COMPOSE) exec app php artisan migrate --force
	$(COMPOSE) exec app php artisan db:seed --force
	@echo ""
	@echo "BUFFALO is running at http://localhost:8000 (Mailpit: http://localhost:8025)."
	@echo "Map tiles are not included; see 'make tiles'."

up: wayfinder ## Start the stack
	$(COMPOSE) up -d --wait

down: ## Stop the stack
	$(COMPOSE) down

shell: ## Open a shell in the app container
	$(COMPOSE) exec app bash

artisan: ## Run an Artisan command: make artisan cmd="migrate"
	$(COMPOSE) exec app php artisan $(cmd)

test: ## Run the test suites: Pest (against buffalo_test) and Vitest
	$(PHP) php artisan config:clear --ansi
	$(PHP) php artisan test
	$(NODE) npm run test

lint: ## Pint (test), Larastan, ESLint, Prettier (check), tsc --noEmit
	$(PHP_ND) vendor/bin/pint --test
	$(PHP_ND) vendor/bin/phpstan analyse --no-progress --memory-limit=1G
	$(NODE) npm run lint
	$(NODE) npm run format:check
	$(NODE) npm run typecheck

fix: ## Apply Pint and Prettier fixes
	$(PHP_ND) vendor/bin/pint
	$(NODE) npm run format

fresh: ## Drop all tables, re-run migrations (as buffalo_owner) and seeders
	$(COMPOSE) exec app php artisan migrate:fresh --seed

logs: ## Follow container logs
	$(COMPOSE) logs -f --tail=100

tiles: ## Download the Nigeria map extract: make tiles url=<protomaps build URL>
	./scripts/fetch-map-tiles.sh $(url)

uat-up: ## UAT (production images, APP_ENV=uat, synthetic data): https://localhost:19443; demo: DEMO_HOST=host [DEMO_PORT=8090]
	DEMO_HOST="$(DEMO_HOST)" DEMO_PORT="$(DEMO_PORT)" ./scripts/uat/up.sh

uat-down: ## Stop the UAT environment or demo (PURGE=1 also deletes its volumes; UAT_PURGE=1 also removes .uat/)
	PURGE="$(PURGE)" UAT_PURGE="$(UAT_PURGE)" ./scripts/uat/down.sh

uat-credentials: ## New passwords and TOTP secrets for the UAT accounts (printed once)
	docker compose --env-file .uat/buffalo.env run --rm -T tools php artisan buffalo:uat:seed --reset-credentials

browser-check: ## Phase 11 browser check (Playwright): target=local (default, make up) or target=uat (make uat-up)
	TARGET="$(target)" ./scripts/uat/browser-check.sh $(args)

release: ## Signed release: make release VERSION=x.y.z RELEASE_KEY=/path/outside/repo [SINCE=ref]
	VERSION=$(VERSION) RELEASE_KEY=$(RELEASE_KEY) SINCE=$(SINCE) ./scripts/release.sh

images: ## Build the BUFFALO-patched base images (before the first build, and weekly)
	./scripts/build-patched-images.sh dev

restore-drill: ## Restore the latest backups into an isolated project, verify, report timings (docs/drills)
	./scripts/restore/drill.sh $(args)

security-scan: ## CI security scans: make security-scan what=trivy|zap (reports in build/security)
	./scripts/security-scan.sh $(what)

clamav-import-signatures: ## Import ClamAV signature files (air-gapped): make clamav-import-signatures dir=<path>
	./scripts/clamav-import-signatures.sh $(dir)

wayfinder: ## Regenerate Wayfinder TypeScript routes (run after changing routes)
	$(PHP_ND) php artisan wayfinder:generate --with-form

ci: images ## Run scripts/ci.sh in a clean copy of the working tree (Docker)
	./scripts/check-compose-hardening.sh
	python3 scripts/security/check-exceptions.py
	docker build -f docker/php/Dockerfile --target ci \
		--build-arg UID=$(DOCKER_UID) --build-arg GID=$(DOCKER_GID) -t buffalo-app:ci .
	$(COMPOSE) up -d --wait postgres valkey gotenberg clamav
	@# The stack's networks have no gateway; Composer and npm need one (buffalo_ci).
	docker network inspect buffalo_ci >/dev/null 2>&1 || docker network create buffalo_ci >/dev/null
	docker run --rm --network buffalo_ci --network buffalo_backend --network buffalo_internal-tools \
		-v "$(CURDIR)":/src:ro \
		-e DB_HOST=postgres -e REDIS_HOST=valkey -e GOTENBERG_URL=http://gotenberg:3000 \
		-e CLAMAV_HOST=clamav -e BUFFALO_REQUIRE_CLAMAV=true \
		buffalo-app:ci bash -c '\
			mkdir -p /tmp/ci && \
			tar -C /src --exclude=./node_modules --exclude=./vendor --exclude=./.env \
				--exclude=./public/build --exclude=./public/hot --exclude=./.git \
				--exclude=./tests/browser/node_modules --exclude=./tests/browser/test-results \
				--exclude=./.uat --exclude=./.local-check -cf - . \
				| tar -C /tmp/ci -xf - && \
			cd /tmp/ci && scripts/ci.sh'
