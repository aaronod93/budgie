#!/usr/bin/env bash
#
# Production deploy for Budgie on the shared VPS (see docs/DEPLOY.md).
# Rebuilds the containers and runs database migrations.
#
#   cd /var/www/budgie
#   git pull
#   ./scripts/deploy-prod.sh
#
set -euo pipefail

cd "$(dirname "$0")/.."   # repo root

COMPOSE="docker compose -f docker-compose.prod.yml"

echo "==> Building and starting containers"
$COMPOSE up -d --build

echo "==> Running database migrations"
$COMPOSE exec -T api php artisan migrate --force

echo "==> Done. Container status:"
$COMPOSE ps
