#!/usr/bin/env bash
#
# Build a downloadable install/upgrade package.
#
#   ./deploy/make-package.sh
#
# Produces dist/websoft-service-erp-<date>-<commit>.tar.gz and a
# matching .sha256.
#
# The archive is the source tree the Docker images build FROM -- not
# built images. It deliberately excludes node_modules and vendor
# (the images install their own, pinned by the lockfiles, which ARE
# included) and any local .env (secrets are generated on the target
# server, never shipped).

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
DIST="$ROOT/dist"

command -v git >/dev/null 2>&1 || { echo "ERROR: git is needed to stamp the package." >&2; exit 1; }

COMMIT="$(git rev-parse --short HEAD)"
STAMP="$(date -u '+%Y%m%d')"
NAME="websoft-service-erp-$STAMP-$COMMIT"

if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "WARNING: uncommitted changes -- the package will include them," >&2
  echo "         but its name says $COMMIT, which will not match." >&2
fi

mkdir -p "$DIST"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$NAME"
# Copy the working tree, not `git archive`, so an intentionally
# uncommitted fix still packages -- with the warning above.
tar -cf - \
  --exclude='./.git' \
  --exclude='./dist' \
  --exclude='./backups' \
  --exclude='./.env' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='__pycache__' \
  --exclude='.venv' \
  --exclude='*.tar.gz' \
  --exclude='./backend-php/storage/app/uploads' \
  --exclude='./backend-php/storage/logs' \
  --exclude='.phpunit.result.cache' \
  -C "$ROOT" . | tar -xf - -C "$STAGE/$NAME"

cat > "$STAGE/$NAME/PACKAGE.txt" <<META
Websoft Service ERP Solution
Package  $NAME
Commit   $(git rev-parse HEAD)
Committed $(TZ=Asia/Singapore git log -1 --format=%cd --date=format-local:%d/%m/%Y)
Version  $(. deploy/version.sh && echo "$APP_VERSION")
Subject  $(git log -1 --pretty=%s)
Built    $(date -u '+%Y-%m-%d %H:%M:%S UTC')

Install:  ./deploy/install.sh
Upgrade:  ./deploy/upgrade.sh --no-pull
Docs:     deploy/README.md
META

tar -czf "$DIST/$NAME.tar.gz" -C "$STAGE" "$NAME"
( cd "$DIST" && sha256sum "$NAME.tar.gz" > "$NAME.tar.gz.sha256" )

echo
echo "  $DIST/$NAME.tar.gz  ($(du -h "$DIST/$NAME.tar.gz" | cut -f1))"
echo "  $DIST/$NAME.tar.gz.sha256"
echo
echo "  scp $DIST/$NAME.tar.gz you@test-server:~/"
echo
