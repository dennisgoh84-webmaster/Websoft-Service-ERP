# The self-test runner for the nightly run (selftest/docker-compose.yml).
# Playwright's own image carries the browsers; the npm package is added
# at the exact version the frontend uses, which run-server.sh reads from
# frontend/package-lock.json -- the two must match, or the browsers the
# image carries are not the ones that package drives.
ARG PLAYWRIGHT_VERSION=1.63.0
FROM mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble
ARG PLAYWRIGHT_VERSION
WORKDIR /opt/selftest
RUN npm init -y >/dev/null && npm install --no-audit --no-fund "playwright@${PLAYWRIGHT_VERSION}"
