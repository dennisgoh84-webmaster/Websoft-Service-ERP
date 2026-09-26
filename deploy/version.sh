# Sourced by install.sh, upgrade.sh and upgrade-agent.sh (2026-09-26):
# the ERP's version number, e.g. 1.0.214 -- the major.minor in the repo
# root's VERSION file (changed by hand only for a big release), then the
# number of commits in the code's history, so it goes up by itself with
# every change released and two different builds never share a number.
# APP_VERSION_DATE is that commit's date in Singapore time, DD/MM/YYYY.
# The build stamps both on the login screen, and the upgrade agent
# reports them to Central Command's Client Upgrades, so the two tally.
# A packaged install (no .git) reads them from the PACKAGE.txt that
# deploy/make-package.sh writes.

# erp_version <git ref> -> e.g. 1.0.214
erp_version() {
  local base
  base="$(git show "$1:VERSION" 2>/dev/null | tr -d '[:space:]')"
  echo "${base:-1.0}.$(git rev-list --count "$1")"
}

APP_VERSION=""
APP_VERSION_DATE=""
if git rev-parse --git-dir >/dev/null 2>&1; then
  # A shallow clone would count only the commits it holds, so the number
  # would be too low; fetch the full history once.
  if [ "$(git rev-parse --is-shallow-repository 2>/dev/null)" = true ]; then
    git fetch -q --unshallow origin 2>/dev/null \
      || echo "warning: shallow clone and could not fetch full history -- the version number will be too low" >&2
  fi
  APP_VERSION="$(erp_version HEAD)"
  APP_VERSION_DATE="$(TZ=Asia/Singapore git log -1 --format=%cd --date=format-local:%d/%m/%Y HEAD)"
elif [ -f PACKAGE.txt ]; then
  APP_VERSION="$(awk '$1 == "Version" { print $2 }' PACKAGE.txt)"
  APP_VERSION_DATE="$(awk '$1 == "Committed" { print $2 }' PACKAGE.txt)"
fi
export APP_VERSION APP_VERSION_DATE
