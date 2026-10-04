#!/bin/sh
# Runs on the server from cron (every couple of minutes). When a newly deployed
# revision shows up it applies migrations and refreshes the Laravel caches,
# then records the revision so the work happens once per deploy.
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)" || exit 1
cd "$APP_DIR" || exit 1

REV_FILE=.deploy-revision
STAMP=storage/.deployed-revision
LOG=storage/logs/deploy.log
ENV_BACKUP="$APP_DIR/../.env.fyp"

[ -f "$REV_FILE" ] || exit 0
NEW="$(cat "$REV_FILE")"
[ "$NEW" = "$(cat "$STAMP" 2>/dev/null)" ] && exit 0

PHP=/opt/alt/php83/usr/bin/php
[ -x "$PHP" ] || PHP=php

# storage/app/public and storage/logs are symlinks into this folder (see deploy/publish.sh).
mkdir -p "$APP_DIR/../persist/public" "$APP_DIR/../persist/logs"
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache

# Safety net: keep a copy of .env outside the web root and restore it if a deploy ever removes it.
if [ ! -f .env ] && [ -f "$ENV_BACKUP" ]; then cp "$ENV_BACKUP" .env; fi
[ -f .env ] || { echo "$(date -u +%FT%TZ) no .env found, aborting" >> "$LOG"; exit 1; }
[ -f "$ENV_BACKUP" ] || { cp .env "$ENV_BACKUP" && chmod 600 "$ENV_BACKUP"; }

{
  echo "$(date -u +%FT%TZ) deploying ${NEW}"
  $PHP artisan migrate --force &&
  $PHP artisan config:cache &&
  $PHP artisan view:cache &&
  echo "$NEW" > "$STAMP" &&
  echo "$(date -u +%FT%TZ) done ${NEW}"
} >> "$LOG" 2>&1
