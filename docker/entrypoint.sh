#!/bin/sh
set -e

# The caches are built here and not in the image because they read the environment of this container.
php artisan optimize

# For the web role this starts FrankenPHP (the default command of the image), for the others the given command.
exec docker-php-entrypoint "$@"
