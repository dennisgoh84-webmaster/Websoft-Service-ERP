#!/usr/bin/env bash
#
# Installs (or refreshes) the nightly self-test on this test server
# (docs/self-test.md): a systemd timer that runs selftest/run-server.sh
# at 02:30 Singapore time and emails the pass/fail result.
#
#   sudo ./deploy/install-selftest.sh dennis@example.com[,nico@example.com]
#   sudo ./deploy/install-selftest.sh            # keep the recipients in .env
#
# The recipients are kept in .env as SELFTEST_EMAIL_TO. The email goes
# out through the System Email mailbox (Maintenance -> System Email), so
# that must be set up for the email to arrive. Idempotent.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
UNIT="websoft-selftest"
RUN_USER="${SUDO_USER:-$(id -un)}"
WHEN="*-*-* 02:30:00 Asia/Singapore"

say()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[33m    %s\033[0m\n' "$*"; }

[ -f .env ] || { warn "No .env yet -- run deploy/install.sh first."; exit 1; }

if [ -n "${1:-}" ]; then
  if grep -qE '^SELFTEST_EMAIL_TO=' .env; then
    sed -i "s|^SELFTEST_EMAIL_TO=.*|SELFTEST_EMAIL_TO=$1|" .env
  else
    printf '\n# Who gets the nightly self-test email (comma-separated); docs/self-test.md.\nSELFTEST_EMAIL_TO=%s\n' "$1" >> .env
  fi
  [ "$(id -u)" -eq 0 ] && [ -n "${SUDO_USER:-}" ] && chown "$SUDO_USER": .env
  echo "    SELFTEST_EMAIL_TO=$1"
elif ! grep -qE '^SELFTEST_EMAIL_TO=.+' .env; then
  warn "No recipient yet: the self-test will run, but email nobody. Re-run with an address:"
  warn "  sudo ./deploy/install-selftest.sh you@example.com"
fi

chmod +x selftest/run-server.sh

if ! command -v systemctl >/dev/null 2>&1; then
  warn "systemd not found -- add this cron line instead (runs as $RUN_USER; the server's clock must be Singapore time):"
  warn "  30 2 * * * $ROOT/selftest/run-server.sh >/dev/null 2>&1"
  exit 0
fi

SUDO=""
if [ "$(id -u)" -ne 0 ]; then
  if sudo -n true 2>/dev/null; then SUDO="sudo -n"; else
    warn "Cannot write systemd units without sudo. Run: sudo $ROOT/deploy/install-selftest.sh"
    exit 1
  fi
fi

say "Installing the $UNIT systemd timer (02:30 Singapore time, runs as $RUN_USER)"
$SUDO tee "/etc/systemd/system/$UNIT.service" >/dev/null <<UNITEOF
[Unit]
Description=Websoft Service ERP nightly self-test
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=$RUN_USER
WorkingDirectory=$ROOT
ExecStart=$ROOT/selftest/run-server.sh
TimeoutStartSec=3h
UNITEOF

$SUDO tee "/etc/systemd/system/$UNIT.timer" >/dev/null <<UNITEOF
[Unit]
Description=Run the Websoft Service ERP self-test every night

[Timer]
OnCalendar=$WHEN
Persistent=true

[Install]
WantedBy=timers.target
UNITEOF

$SUDO systemctl daemon-reload
$SUDO systemctl enable --now "$UNIT.timer" >/dev/null
echo "    active: $($SUDO systemctl is-active "$UNIT.timer")  --  next run: $($SUDO systemctl list-timers "$UNIT.timer" --no-legend | awk '{print $1, $2, $3}')"
echo "    run it now: sudo systemctl start $UNIT.service   (or ./selftest/run-server.sh)"
echo "    reports:    $ROOT/selftest/results/latest/report.html"
