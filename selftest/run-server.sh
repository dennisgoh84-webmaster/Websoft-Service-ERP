#!/usr/bin/env bash
#
# The nightly self-test on the test server (docs/self-test.md), run by
# the websoft-selftest.timer that deploy/install-selftest.sh installs --
# or by hand:
#
#   ./selftest/run-server.sh            # run, then email the result
#   ./selftest/run-server.sh --no-email # run only
#
# It builds a separate, throwaway copy of the app from this checkout
# (selftest/docker-compose.yml: its own database in memory, no mailbox,
# nothing published on the host), walks every screen on a desktop and a
# phone-sized screen, keys in the main forms, then removes that copy.
# The real stack is only used to SEND the result, through the System
# Email mailbox (Maintenance -> System Email), to SELFTEST_EMAIL_TO in
# .env. A run that cannot even start still sends an email saying why.
#
# Reports: selftest/results/<date-time>/report.html (+ failure
# screenshots); the last 14 are kept, selftest/results/latest points at
# the newest.

set -uo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
EMAIL=1
[ "${1:-}" = "--no-email" ] && EMAIL=0

STAMP="$(TZ=Asia/Singapore date '+%Y%m%d-%H%M')"
OUT="$ROOT/selftest/results/$STAMP"
LOG="$OUT/run.log"
mkdir -p "$OUT"
exec > >(tee -a "$LOG") 2>&1

say() { printf '\n==> %s\n' "$*"; }

# One run at a time: a slow night must not overlap the next.
exec 9>/tmp/websoft-selftest.lock
flock -n 9 || { echo "A self-test is already running; not starting another."; exit 0; }

env_value() { grep -E "^$1=" .env 2>/dev/null | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
TO="$(env_value SELFTEST_EMAIL_TO)"

export SELFTEST_OUT="$OUT"
# The runner writes the report as this user, so old reports can be cleared.
export SELFTEST_UID="$(id -u)" SELFTEST_GID="$(id -g)"
export SELFTEST_COMMIT="$(git log -1 --format='%h %s' 2>/dev/null || echo unknown)"
export SELFTEST_SERVER_NAME="$(hostname)"
export SELFTEST_APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
export SELFTEST_JWT_SECRET="$(head -c 32 /dev/urandom | base64 | tr -d '/+=')"
export SELFTEST_PLAYWRIGHT_VERSION="$(grep -A3 '"node_modules/playwright"' frontend/package-lock.json | sed -n 's/.*"version": "\(.*\)".*/\1/p' | head -1)"
C="docker compose -p websoft-selftest -f selftest/docker-compose.yml"

send() {
  [ "$EMAIL" -eq 1 ] || return 0
  if [ -z "$TO" ]; then
    echo "SELFTEST_EMAIL_TO is not set in .env, so no email was sent (report: $OUT/report.html)."
    return 0
  fi
  local args=()
  IFS=',' read -ra addrs <<< "$TO"
  for a in "${addrs[@]}"; do args+=(--to="$(echo "$a" | xargs)"); done
  # Through the REAL stack, which has the System Email mailbox.
  docker compose exec -T backend-php php artisan selftest:report "$@" "${args[@]}"
}

fail_to_start() {
  echo "$1"
  $C down -v --remove-orphans >/dev/null 2>&1
  { echo "$1"; echo; echo "Last lines of the log ($LOG):"; tail -40 "$LOG"; } | send --error
  exit 2
}

say "Self-test $STAMP -- $SELFTEST_COMMIT"
[ -n "$SELFTEST_PLAYWRIGHT_VERSION" ] || fail_to_start "Could not read the Playwright version from frontend/package-lock.json."

say "Building the self-test copy of the app"
$C build || fail_to_start "The self-test copy of the app did not build."

say "Running (a fresh database, every screen, desktop + phone)"
$C run --rm runner
STATUS=$?
$C down -v --remove-orphans >/dev/null 2>&1

[ -f "$OUT/report.json" ] || fail_to_start "The self-test stopped before writing its report (exit $STATUS)."

ln -sfn "$OUT" "$ROOT/selftest/results/latest"
ls -1d "$ROOT"/selftest/results/2* 2>/dev/null | head -n -14 | xargs -r rm -rf

say "Sending the result"
send < "$OUT/report.json" || echo "Could not send the email (see above); the report is at $OUT/report.html"
exit $STATUS
