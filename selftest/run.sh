#!/usr/bin/env bash
#
# The self-test program, in one command (docs/self-test.md):
#
#   ./selftest/run.sh                 # everything, desktop + phone
#   ./selftest/run.sh --only phone    # one screen size
#   ./selftest/run.sh --grep Contract # only checks whose name matches
#   ./selftest/run.sh --serve         # just start the throwaway app and
#                                     # leave it up (for writing a new check:
#                                     # node selftest/runner/run.mjs --base ...)
#
# For a dev machine with PHP, PostgreSQL and Node (DEV_SETUP.md). It
# builds a THROWAWAY database (websoft_selftest -- the rebuild refuses
# any name not ending in _selftest), starts its own backend and a
# production build of the frontend on spare ports beside your usual dev
# servers, walks every screen, and stops them again. Your own database
# is never touched. The nightly run on the test server is
# selftest/run-server.sh, which does the same in Docker and emails the
# result.
#
# Report: selftest/results/latest/report.html (+ screenshots of failures).
# Exit code: 0 all passed, 1 something failed, 2 could not run.

set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"

DB="${SELFTEST_DB:-websoft_selftest}"
API_PORT="${SELFTEST_API_PORT:-8100}"
WEB_PORT="${SELFTEST_WEB_PORT:-4180}"
OUT="$ROOT/selftest/results/latest"
WORK="$(mktemp -d /tmp/websoft-selftest.XXXXXX)"
PIDS=()

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[31mERROR: %s\033[0m\n\n' "$*" >&2; exit 2; }
# Each server runs in its own process group, so stopping it stops what
# it started too (npx -> vite, artisan serve -> its PHP workers); a
# leftover server would otherwise answer the next run from a folder that
# no longer exists.
start_bg() {
  if command -v setsid >/dev/null; then setsid "$@" & else "$@" & fi
  PIDS+=($!)
}
cleanup() {
  for p in "${PIDS[@]:-}"; do [ -n "$p" ] && { kill -- "-$p" 2>/dev/null || kill "$p" 2>/dev/null || true; }; done
  rm -rf "$WORK"
}
trap cleanup EXIT

command -v php >/dev/null || die "php is not installed (DEV_SETUP.md)."
command -v node >/dev/null || die "node is not installed (DEV_SETUP.md)."
[ -d frontend/node_modules/playwright ] || die "Run 'npm ci' in frontend/ first."

# The throwaway backend: its own database, its own uploads folder, and no
# way to send email or WhatsApp -- nothing the self-test keys in can
# reach a real person.
export DB_DATABASE="$DB" UPLOADS_DIR="$WORK/uploads" APP_ENV=selftest \
  SMTP_HOST= SMTP_USERNAME= SMTP_PASSWORD= SMTP_FROM_EMAIL= \
  TWILIO_ACCOUNT_SID= TWILIO_AUTH_TOKEN= TWILIO_WHATSAPP_FROM= \
  PHP_CLI_SERVER_WORKERS=4
mkdir -p "$UPLOADS_DIR"

for port in "$API_PORT" "$WEB_PORT"; do
  if curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$port/"; then
    die "Port $port is already in use (a self-test left running?). Stop it, or set SELFTEST_API_PORT / SELFTEST_WEB_PORT."
  fi
done

say "Rebuilding the self-test database ($DB)"
(cd backend-php && php artisan selftest:prepare-db) > "$WORK/prepare.log" 2>&1 || { cat "$WORK/prepare.log"; die "Could not build the self-test database."; }

say "Starting the self-test backend on :$API_PORT"
start_bg bash -c "cd backend-php && exec php artisan serve --host=127.0.0.1 --port=$API_PORT" > "$WORK/backend.log" 2>&1

say "Building the frontend"
(cd frontend && npx vite build --outDir "$WORK/dist" --emptyOutDir) > "$WORK/build.log" 2>&1 || { tail -30 "$WORK/build.log"; die "The frontend did not build."; }

say "Serving it on :$WEB_PORT"
start_bg bash -c "cd frontend && API_TARGET=http://127.0.0.1:$API_PORT exec npx vite preview --outDir '$WORK/dist' --host 127.0.0.1 --port $WEB_PORT --strictPort" > "$WORK/web.log" 2>&1

for i in $(seq 1 60); do
  curl -sf -o /dev/null "http://127.0.0.1:$WEB_PORT/api/health" && break
  sleep 1
  [ "$i" = 60 ] && { tail -20 "$WORK/backend.log" "$WORK/web.log"; die "The self-test app did not come up."; }
done

if [ "${1:-}" = "--serve" ]; then
  say "The self-test app is up: http://127.0.0.1:$WEB_PORT (dennis@websoft.example / demo1234). Ctrl+C stops it."
  echo "    node selftest/runner/run.mjs --base http://127.0.0.1:$WEB_PORT --grep <check name>"
  wait
  exit 0
fi

say "Running the self-test"
set +e
node selftest/runner/run.mjs --base "http://127.0.0.1:$WEB_PORT" --out "$OUT" "$@"
STATUS=$?
set -e
exit $STATUS
