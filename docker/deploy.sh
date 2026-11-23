#!/bin/sh
# Deploys one commit on the server. Run it from the checkout of the repository on the server:
#   docker/deploy.sh <commit sha>
set -e

cd "$(dirname "$0")/.."

compose="docker compose -f compose.prod.yml"

git fetch --quiet origin main
git reset --hard "$1"

$compose build

# The database must be up and migrated before the new containers start serving.
$compose up -d db
$compose run --rm web php artisan migrate --force
$compose run --rm web php artisan db:seed --force

$compose up -d --remove-orphans
docker image prune -f
