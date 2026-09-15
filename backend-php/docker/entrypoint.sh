#!/bin/sh
# Start php-fpm, then nginx in the foreground so the container's
# lifetime follows nginx and a crash of either stops the container
# rather than leaving a half-running one.
set -e

php-fpm --daemonize

# Config caching is deliberately NOT done here: the compose file passes
# settings as environment variables, and a cached config would freeze
# whatever was set at build time instead.
exec nginx -g 'daemon off;'
