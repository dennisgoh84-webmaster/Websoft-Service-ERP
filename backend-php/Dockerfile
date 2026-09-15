# PHP backend image -- Laravel + php-fpm behind nginx, matching how
# ../backend/Dockerfile packages the Python one. Run behind the nginx
# container (see ../frontend/Dockerfile), which serves the frontend and
# reverse-proxies /api/* here, so both sides share one origin and the
# backend needs no CORS config at all (see DEPLOY.md).
#
# NOTE this image exists so the PHP backend HAS a deployment path; it
# does not by itself switch anything over. docker-compose.yml still
# points nginx at the Python `backend` service, and moving production
# across is Dennis's call, not a side effect of adding a Dockerfile --
# see DEPLOY.md.
FROM php:8.4-fpm-bookworm

# nginx lives INSIDE this image, in front of php-fpm, so the container
# speaks plain HTTP on :8000 exactly as the Python backend does. That
# makes it a drop-in swap: the frontend's nginx keeps one
# `proxy_pass http://<service>:8000`, with no fastcgi_pass and no need
# to share Laravel's public/ directory across two containers.

# LibreOffice (Writer specifically, not just -core) is needed for the
# "Email X" button's docx -> PDF conversion -- App\Services\PdfConvert
# shells out to `soffice --headless --convert-to pdf`. Leaving it out
# is a SILENT, ONE-SIDED failure: the Word download keeps working while
# every Email button fails with "PDF conversion failed", because both
# are fed by the same .docx. DEV_SETUP.md carries the same warning for
# local installs.
#
# The PHP extensions: pdo_pgsql for Postgres, gd (with JPEG + FreeType,
# needed by App\Services\MobileFileStorage's chop-photo watermarking),
# zip for PhpSpreadsheet/PHPWord, plus intl and bcmath.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libreoffice-writer \
        fonts-dejavu-core \
        nginx \
        libpq-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libicu-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql gd zip intl bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first, so a code-only change does not re-resolve them.
# --no-scripts because artisan is not copied in yet.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/app/uploads storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

# Migrations run as a separate one-shot step (the `migrate-php` service
# in docker-compose.yml), so a rolling restart never re-runs them --
# same split as the Python image.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
