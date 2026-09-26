#!/usr/bin/env bash
#
# The full before-push gate (CLAUDE.md, "The test suite gates main";
# docs/self-test.md), in one command:
#
#   1. Pint (code style)            3. the frontend build
#   2. the backend test suite       4. the self-test (every screen,
#                                      desktop + phone, key-in flows)
#
# Run it by hand before pushing, or let git run it on every push:
#
#   git config core.hooksPath .githooks
#
# Red at any step stops the push.
set -euo pipefail
cd "$(dirname "$0")/.."

step() { printf '\n\033[1m==> [%s] %s\033[0m\n' "$1" "$2"; }

step 1/4 "Pint"
(cd backend-php && ./vendor/bin/pint --test)

step 2/4 "Backend test suite"
(cd backend-php && php artisan test)

step 3/4 "Frontend build"
(cd frontend && npm run build >/dev/null)

step 4/4 "Self-test"
./selftest/run.sh

printf '\n\033[32mAll four green -- OK to push.\033[0m\n'
