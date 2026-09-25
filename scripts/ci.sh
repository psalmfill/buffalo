#!/usr/bin/env bash
#
# BUFFALO continuous integration checks.
#
# All CI logic lives here so it can move between CI systems unchanged. The
# script expects PHP 8.4 (with the extensions from docker/php/Dockerfile),
# Composer, Node.js and npm on PATH, and reachable PostgreSQL/PostGIS and
# Valkey services (DB_HOST / REDIS_HOST etc. from the environment or .env).
#
# Locally, run `make ci`: it runs this script in the `ci` Docker image against
# a clean copy of the working tree.
#
# Stops at the first failing step.

set -euo pipefail

cd "$(dirname "$0")/.."

step() {
    printf '\n\033[1;34m==> %s\033[0m\n' "$1"
}

if [[ ! -f .env ]]; then
    cp .env.example .env
fi

step "Composer install"
composer install --no-interaction --no-progress --prefer-dist

if ! grep -Eq '^APP_KEY=.+' .env; then
    php artisan key:generate --no-interaction
fi

step "npm install"
npm ci --no-audit --no-fund

step "Frontend build"
npm run build

step "Pint (test mode)"
vendor/bin/pint --test

step "Larastan"
vendor/bin/phpstan analyse --no-progress --memory-limit=1G

step "ESLint"
npm run lint

step "Prettier check"
npm run format:check

step "TypeScript typecheck"
npm run typecheck

step "Prepare test database"
# CI service containers cannot run docker/postgres/init, so make sure the
# test database and its extensions exist (idempotent).
php <<'PHP'
<?php
require 'vendor/autoload.php';
$env = Dotenv\Dotenv::createImmutable(getcwd());
$env->safeLoad();
$get = fn (string $key, string $default) => $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
$dsn = sprintf('pgsql:host=%s;port=%s', $get('DB_HOST', '127.0.0.1'), $get('DB_PORT', '5432'));
$user = $get('DB_USERNAME', 'buffalo');
$password = $get('DB_PASSWORD', 'buffalo');

$pdo = new PDO($dsn.';dbname=postgres', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (! $pdo->query("SELECT 1 FROM pg_database WHERE datname = 'buffalo_test'")->fetchColumn()) {
    $pdo->exec('CREATE DATABASE buffalo_test');
}
$test = new PDO($dsn.';dbname=buffalo_test', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['postgis', 'pg_trgm', 'unaccent'] as $extension) {
    $test->exec("CREATE EXTENSION IF NOT EXISTS {$extension}");
}
echo "buffalo_test ready\n";
PHP

step "Pest"
php artisan config:clear --no-interaction
php artisan test

printf '\n\033[1;32mAll CI checks passed.\033[0m\n'
