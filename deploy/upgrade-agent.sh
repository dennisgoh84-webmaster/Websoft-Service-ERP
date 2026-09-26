#!/usr/bin/env bash
#
# Websoft Service ERP -- host-side upgrade agent.
#
# The app runs inside Docker images, so nothing inside a container can
# pull new code or rebuild. This script is the piece that CAN: it runs
# on the host once a minute (systemd timer, installed by
# deploy/install-upgrade-agent.sh, which install.sh and upgrade.sh both
# call), and on every tick it:
#
#   1. Sends the backend a heartbeat: the commit checked out here, what
#      origin/main is at, and how many commits behind we are. Central
#      Command shows that as current / latest / "behind by N".
#   2. Takes the next pending row of `upgrade_requests` (Central Command
#      inserts them), runs `deploy/upgrade.sh <target_ref>` with its
#      output captured to backups/upgrade-logs/, and reports the result
#      back -- retrying for a while, because the upgrade restarts the
#      very backend it reports to.
#
# Overrides (all optional, for testing without Docker):
#   UPGRADE_AGENT_API          base URL of the two agent endpoints
#   UPGRADE_AGENT_UPGRADE_CMD  command run instead of deploy/upgrade.sh
#
# Needs: bash, git, curl (>= 7.76 for --fail-with-body), python3, flock.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
[ -f .env ] || exit 0
set -a; . ./.env; set +a

TOKEN="${UPGRADE_AGENT_TOKEN:-}"
[ -n "$TOKEN" ] || { echo "UPGRADE_AGENT_TOKEN is not set in .env -- run deploy/install-upgrade-agent.sh" >&2; exit 1; }
API="${UPGRADE_AGENT_API:-http://127.0.0.1:${HTTP_PORT:-80}/api/system/upgrade-agent}"
UPGRADE_CMD="${UPGRADE_AGENT_UPGRADE_CMD:-$ROOT/deploy/upgrade.sh}"
LOG_DIR="$ROOT/backups/upgrade-logs"
AGENT_LOG="$LOG_DIR/agent.log"

# Fail LOUDLY (stderr goes to the systemd journal, and a non-zero exit
# marks the unit failed) when this tick cannot work -- otherwise it exits
# silently every minute and the screen only says "has not reported in".
# The usual cause is files left owned by root by an earlier sudo run.
ME="$(id -un)"
fail() { echo "upgrade agent: $*" >&2; exit 1; }
[ "$(id -u)" -ne 0 ] || fail "refusing to run as root -- files it wrote would be root-owned and block $ROOT's owner. Run it as that user (sudo deploy/install-upgrade-agent.sh sets the timer up that way)."
mkdir -p "$LOG_DIR" 2>/dev/null && [ -w "$LOG_DIR" ] \
  || fail "cannot write $LOG_DIR as $ME. Fix: sudo chown -R $ME: $ROOT"
for f in "$AGENT_LOG" "$LOG_DIR/.agent.lock"; do
  [ ! -e "$f" ] || [ -w "$f" ] || fail "cannot write $f as $ME. Fix: sudo chown -R $ME: $ROOT"
done
OTHER=$(find "$ROOT/.git" ! -user "$ME" -print -quit 2>/dev/null)
[ -z "$OTHER" ] || fail "$OTHER is not owned by $ME, so git fetch and upgrades will fail. Fix: sudo chown -R $ME: $ROOT"

# One tick at a time: an upgrade takes minutes, and the timer keeps firing.
exec 9>"$LOG_DIR/.agent.lock"
flock -n 9 || exit 0

log() { printf '%s %s\n' "$(date '+%d/%m/%Y %H:%M:%S')" "$*" >>"$AGENT_LOG"; }

# api <path> <json | @file>  -- prints the response body; non-zero on any HTTP or transport error.
api() {
  curl -sS --fail-with-body -m 30 -X POST "$API/$1" \
    -H "X-Upgrade-Agent-Token: $TOKEN" -H 'Content-Type: application/json' \
    --data-binary "$2"
}

# ---- 0. deliver any report a previous tick could not (backend was restarting) ----
for f in "$LOG_DIR"/pending-report-*.json; do
  [ -e "$f" ] || continue
  if api report "@$f" >/dev/null 2>>"$AGENT_LOG"; then
    log "delivered late report $(basename "$f")"
    rm -f "$f"
  fi
done

# ---- 1. heartbeat ----
git fetch -q origin main 2>>"$AGENT_LOG" || log "git fetch failed (offline?) -- reporting last known origin/main"
CUR_SHA=$(git rev-parse HEAD)
CUR_SUB=$(git log -1 --pretty=%s HEAD)
CUR_DATE=$(git log -1 --pretty=%cI HEAD)
. deploy/version.sh   # erp_version; also unshallows a shallow clone once
CUR_VER=$(erp_version HEAD)
REM_SHA=""; REM_SUB=""; REM_DATE=""; REM_VER=""; BEHIND=0
if git rev-parse -q --verify origin/main >/dev/null 2>&1; then
  REM_SHA=$(git rev-parse origin/main)
  REM_SUB=$(git log -1 --pretty=%s origin/main)
  REM_DATE=$(git log -1 --pretty=%cI origin/main)
  REM_VER=$(erp_version origin/main)
  BEHIND=$(git rev-list --count HEAD..origin/main)
fi

HEARTBEAT=$(CUR_SHA="$CUR_SHA" CUR_SUB="$CUR_SUB" CUR_DATE="$CUR_DATE" CUR_VER="$CUR_VER" REM_SHA="$REM_SHA" REM_SUB="$REM_SUB" REM_DATE="$REM_DATE" REM_VER="$REM_VER" BEHIND="$BEHIND" HOST="$(hostname)" python3 - <<'PY'
import json, os
s = lambda k: (os.environ.get(k) or None)
print(json.dumps({
    "current_sha": s("CUR_SHA"), "current_subject": s("CUR_SUB"), "current_committed_at": s("CUR_DATE"),
    "current_version": s("CUR_VER"),
    "remote_sha": s("REM_SHA"), "remote_subject": s("REM_SUB"), "remote_committed_at": s("REM_DATE"),
    "remote_version": s("REM_VER"),
    "commits_behind": int(os.environ.get("BEHIND") or 0), "agent_host": s("HOST"),
}))
PY
)

if ! RESP=$(api heartbeat "$HEARTBEAT" 2>>"$AGENT_LOG"); then
  log "heartbeat failed (backend down or token rejected)"
  echo "upgrade agent: heartbeat to $API failed (backend down or token rejected) -- see $AGENT_LOG" >&2
  exit 0
fi

PARSED=$(printf '%s' "$RESP" | python3 -c "import json, sys; r = json.load(sys.stdin).get('request'); print(r['id'], r['kind'], r['target_ref']) if r else print('')")
read -r REQ_ID REQ_KIND REQ_TARGET <<<"$PARSED"
[ -n "${REQ_ID:-}" ] || exit 0

# ---- 2. run the request ----
UPGRADE_LOG="$LOG_DIR/upgrade-$REQ_ID.log"
log "request $REQ_ID: $REQ_KIND -> $REQ_TARGET (from $CUR_SHA)"
stamp() { date '+%d/%m/%Y %H:%M:%S'; }
{
  echo "== $(stamp) $REQ_KIND to $REQ_TARGET requested; currently at $CUR_SHA"
  echo "== running: $UPGRADE_CMD $REQ_TARGET"
} >"$UPGRADE_LOG"

# Run the upgrade in the background and send the log so far every few
# seconds, so the upgrade screen shows the live step and output
# (2026-09-25). A failed progress post (the backend restarting mid-
# upgrade) is simply skipped; the final report below carries the whole log.
progress() {
  REQ_ID="$REQ_ID" LOGFILE="$UPGRADE_LOG" python3 - <<'PY' | curl -sS -m 5 -o /dev/null -X POST "$API/progress" \
      -H "X-Upgrade-Agent-Token: $TOKEN" -H 'Content-Type: application/json' --data-binary @- 2>/dev/null || true
import json, os
with open(os.environ["LOGFILE"], "r", errors="replace") as f:
    text = f.read()
if len(text) > 60000:
    text = "[... earlier output trimmed ...]\n" + text[-60000:]
print(json.dumps({"id": os.environ["REQ_ID"], "log": text}))
PY
}
"$UPGRADE_CMD" "$REQ_TARGET" >>"$UPGRADE_LOG" 2>&1 &
UPGRADE_PID=$!
while kill -0 "$UPGRADE_PID" 2>/dev/null; do
  progress
  for _ in 1 2 3 4 5; do kill -0 "$UPGRADE_PID" 2>/dev/null || break; sleep 1; done
done
wait "$UPGRADE_PID"
RC=$?
if [ "$RC" -eq 0 ]; then
  OK=true; ERR=""
else
  OK=false; ERR="$(basename "$UPGRADE_CMD") exited with status $RC -- see the log"
fi
TO_SHA=$(git rev-parse HEAD)
echo "== $(stamp) finished: success=$OK now at $TO_SHA" >>"$UPGRADE_LOG"
log "request $REQ_ID finished success=$OK at $TO_SHA"

REPORT="$LOG_DIR/pending-report-$REQ_ID.json"
REQ_ID="$REQ_ID" OK="$OK" ERR="$ERR" FROM="$CUR_SHA" TO="$TO_SHA" LOGFILE="$UPGRADE_LOG" python3 - >"$REPORT" <<'PY'
import json, os
with open(os.environ["LOGFILE"], "r", errors="replace") as f:
    text = f.read()
if len(text) > 60000:
    text = "[... earlier output trimmed ...]\n" + text[-60000:]
print(json.dumps({
    "id": os.environ["REQ_ID"], "success": os.environ["OK"] == "true",
    "from_sha": os.environ["FROM"], "to_sha": os.environ["TO"],
    "log": text, "error": os.environ.get("ERR") or None,
}))
PY

# The upgrade just restarted the backend; give it up to 10 minutes to come back.
for _ in $(seq 1 40); do
  if api report "@$REPORT" >/dev/null 2>>"$AGENT_LOG"; then
    rm -f "$REPORT"
    log "request $REQ_ID reported"
    exit 0
  fi
  sleep 15
done
log "request $REQ_ID: could not report yet; will retry on the next tick"
