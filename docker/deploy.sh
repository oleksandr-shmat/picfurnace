#!/bin/sh
# Builds and starts what is checked out in the repository on the server. The workflow first
# updates the checkout (git fetch, git reset --hard <sha>), so this script is always the new one.
set -e

cd "$(dirname "$0")/.."

compose="docker compose -f compose.prod.yml"

$compose build

# The database must be up and migrated before the new containers start serving.
$compose up -d db
$compose run --rm web php artisan migrate --force
$compose run --rm web php artisan db:seed --force

$compose up -d --remove-orphans
docker image prune -f
