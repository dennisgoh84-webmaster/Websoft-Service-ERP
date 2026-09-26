#!/usr/bin/env bash
#
# Upgrade an existing Websoft Service ERP test server to the latest
# code.
#
#   ./deploy/upgrade.sh            # pull latest main, then upgrade
#   ./deploy/upgrade.sh --no-pull  # upgrade the code already checked out
#   ./deploy/upgrade.sh <ref>      # check out that commit/tag (detached)
#                                  # and upgrade to it -- what the upgrade
#                                  # agent runs for Central Command, for
#                                  # rollbacks too
#
# Order matters here and is deliberate:
#
#   1. Back up the database FIRST. Migrations are the one step that
#      cannot be undone by restarting, so the backup is taken before
#      anything touches the schema -- not after.
#   2. Build the new images before stopping anything, so a build
#      failure leaves the running site untouched.
#   3. Migrate, then restart.
#
# Uploaded files and the database live in named Docker volumes, so
# nothing here deletes them. This script never runs `down -v`.

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
# Plain `docker compose`: the stack is the repo root's docker-compose.yml,
# and this script has already cd'd there, so Compose finds .env by itself.
COMPOSE="docker compose"
BACKUP_DIR="$ROOT/backups"

say()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[33m    %s\033[0m\n' "$*"; }
die()  { printf '\n\033[31mERROR: %s\033[0m\n\n' "$*" >&2; exit 1; }

PULL=1
TARGET_REF=""
case "${1:-}" in
  "") ;;
  --no-pull) PULL=0 ;;
  *) TARGET_REF="$1" ;;
esac

command -v docker >/dev/null 2>&1 || die "Docker is not installed."
docker info >/dev/null 2>&1 || die "The Docker daemon is not running, or this user cannot reach it."
[ -f "$ROOT/.env" ] || die "No .env here, so there is nothing to upgrade. For a fresh install: ./deploy/install.sh"

# ---- 1. back up -----------------------------------------------------

say "Backing up the database"
mkdir -p "$BACKUP_DIR"
STAMP="$(date -u '+%Y%m%d-%H%M%S')"
DUMP="$BACKUP_DIR/websoft-$STAMP.sql.gz"

if $COMPOSE ps --status running --services 2>/dev/null | grep -qx db; then
  $COMPOSE exec -T db pg_dump -U websoft_app websoft_service_erp | gzip > "$DUMP"
else
  warn "db is not running -- starting it just to take the backup"
  $COMPOSE up -d db
  sleep 5
  $COMPOSE exec -T db pg_dump -U websoft_app websoft_service_erp | gzip > "$DUMP"
fi

# A dump that failed halfway still leaves a file, so check it is real.
[ -s "$DUMP" ] || die "The backup came out empty -- stopping before any migration runs. Nothing has changed."
echo "    $DUMP ($(du -h "$DUMP" | cut -f1))"

# Keep the last 10; a test server does not need an unbounded history.
ls -1t "$BACKUP_DIR"/websoft-*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm --

# ---- 2. new code, new images ----------------------------------------

if [ -n "$TARGET_REF" ]; then
  say "Checking out $TARGET_REF"
  git fetch origin
  git checkout --detach "$TARGET_REF"
elif [ "$PULL" -eq 1 ]; then
  say "Pulling the latest code"
  # An agent-driven upgrade leaves HEAD detached at a commit; a manual
  # upgrade goes back onto main first so the pull has a branch.
  if [ "$(git rev-parse --abbrev-ref HEAD)" = "HEAD" ]; then
    git checkout main
  fi
  git rev-parse --abbrev-ref HEAD | grep -qx main \
    || warn "Not on main -- pulling into $(git rev-parse --abbrev-ref HEAD) anyway."
  git pull --ff-only
fi
echo "    now at $(git rev-parse --short HEAD) -- $(git log -1 --pretty=%s)"

say "Building images (the running site is still up and serving)"
. deploy/version.sh
echo "    version $APP_VERSION ($APP_VERSION_DATE) -- shown on the login screen"
$COMPOSE build

# ---- 3. migrate and restart -----------------------------------------

say "Applying migrations"
$COMPOSE run --rm migrate-php

say "Restarting the application"
$COMPOSE up -d

# New code may carry a newer agent script or need the token generated.
./deploy/install-upgrade-agent.sh || warn "upgrade agent timer not (re)installed -- see above"

say "Done"
$COMPOSE ps
cat <<REPORT

  Rollback, if this upgrade went wrong:

    git checkout <previous-commit>
    gunzip -c $DUMP \\
      | docker compose exec -T db \\
          psql -U websoft_app -d websoft_service_erp
    ./deploy/upgrade.sh --no-pull

  Restoring the dump replaces the database's current contents, so only
  do it if you mean to discard everything since the backup.

REPORT
