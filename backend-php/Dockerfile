# The backend image -- Laravel + php-fpm behind nginx. Runs behind the
# frontend's nginx container (see ../frontend/Dockerfile), which serves
# the React app and reverse-proxies /api/* here, so both sides share
# one origin and the backend needs no CORS config at all.
FROM php:8.4-fpm-bookworm

# nginx lives INSIDE this image, in front of php-fpm, so the container
# speaks plain HTTP on :8000. The frontend's nginx then keeps one
# `proxy_pass http://backend:8000`, with no fastcgi_pass and no need to
# share Laravel's public/ directory across two containers.

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

# Raises upload_max_filesize/post_max_size above PHP's tiny defaults --
# see that file's own comment for why (the ad banner's video upload).
COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini

WORKDIR /var/www/html

# Dependencies first, so a code-only change does not re-resolve them.
# --no-scripts because artisan is not copied in yet.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
# The writable directories MUST be created BEFORE composer runs. They are
# not in the image: .dockerignore excludes bootstrap/cache/ and the
# storage/framework/* directories on purpose (a host's caches must never
# be baked in), and composer's post-autoload-dump script runs
# `artisan package:discover`, which writes bootstrap/cache/packages.php.
# With the mkdir after it, the build failed on a clean checkout.
#
# The paths are also spelled out one by one rather than written as
# storage/framework/{cache,sessions,views}: RUN uses /bin/sh, which is
# dash on Debian, and dash has no brace expansion -- that form created a
# single literal directory named "{cache,sessions,views}" and left the
# three real ones missing.
RUN mkdir -p \
        storage/app/uploads \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

# Migrations run as a separate one-shot step (the `migrate-php` service
# in docker-compose.yml), so a rolling restart never re-runs them.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
