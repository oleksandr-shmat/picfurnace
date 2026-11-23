#!/bin/sh
# Dumps the database to ~/backups and keeps the last 7 dumps. Run by cron on the server.
set -e

cd "$(dirname "$0")/.."

mkdir -p "$HOME/backups"
file="$HOME/backups/picfurnace-$(date -u +%Y%m%d-%H%M%S).sql.gz"

docker compose -f compose.prod.yml exec -T db pg_dump -U pixelforge pixelforge | gzip > "$file"

# A dump that is empty or cut short must not count as a backup.
if [ ! -s "$file" ] || ! gzip -t "$file"; then
    rm -f "$file"
    echo "Backup failed" >&2
    exit 1
fi

ls -1t "$HOME"/backups/picfurnace-*.sql.gz | tail -n +8 | xargs -r rm -f
