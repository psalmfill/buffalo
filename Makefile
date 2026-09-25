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

.PHONY: help setup up down shell artisan test lint fix fresh logs tiles wayfinder

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

setup: ## First-time setup: .env, images, dependencies, app key, migrations
	@test -f .env || (cp .env.example .env && echo "Created .env from .env.example")
	$(COMPOSE) build
	$(PHP_ND) composer install --no-interaction
	$(NODE) npm ci --no-audit --no-fund
	@grep -Eq '^APP_KEY=.+' .env || $(PHP_ND) php artisan key:generate --no-interaction
	$(MAKE) wayfinder
	$(COMPOSE) up -d --wait
	$(COMPOSE) exec app php artisan migrate --force
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

test: ## Run the test suite (Pest) against buffalo_test
	$(PHP) php artisan config:clear --ansi
	$(PHP) php artisan test

lint: ## Pint (test), Larastan, ESLint, Prettier (check), tsc --noEmit
	$(PHP_ND) vendor/bin/pint --test
	$(PHP_ND) vendor/bin/phpstan analyse --no-progress --memory-limit=1G
	$(NODE) npm run lint
	$(NODE) npm run format:check
	$(NODE) npm run typecheck

fix: ## Apply Pint and Prettier fixes
	$(PHP_ND) vendor/bin/pint
	$(NODE) npm run format

fresh: ## Drop all tables, re-run migrations and seeders
	$(COMPOSE) exec app php artisan migrate:fresh --seed

logs: ## Follow container logs
	$(COMPOSE) logs -f --tail=100

tiles: ## Download the Nigeria map extract: make tiles url=<protomaps build URL>
	./scripts/fetch-map-tiles.sh $(url)

wayfinder: ## Regenerate Wayfinder TypeScript routes (run after changing routes)
	$(PHP_ND) php artisan wayfinder:generate --with-form
