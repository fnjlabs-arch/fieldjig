# SadSha Build — production image (PHP 8.4-FPM + nginx in one image).
#
# One image, two runtimes: the `app` service runs php-fpm, the `web` service
# runs nginx from this same image (see docker-compose.yml). That is what keeps
# the code nginx serves identical to the code php-fpm executes.
#
# Frontend assets are NOT built here: `public/build/` is committed in the
# repo (Vite manifest + hashed assets), so a deploy that changes only PHP
# needs no Node at all. If a release changes frontend source, run
# `npm run build` locally and commit the rebuilt `public/build/` first —
# deploying without it serves stale (or missing) assets.
FROM php:8.4-fpm

# System deps: git + unzip for Composer, curl for the web healthcheck,
# nginx for the `web` service, image/zip/intl libs for the PHP extensions.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl nginx \
        libpng-dev libjpeg-dev libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer (from the official image — no curl|sh installer).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP deps first (better layer caching: composer files change rarely).
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-scripts

# Then the app code. .dockerignore keeps vendor/, node_modules/, .env and
# local artefacts out of the build context (see .dockerignore).
COPY . .

# nginx vhost for the `web` service (the `app` service ignores it).
COPY deploy/nginx.conf /etc/nginx/conf.d/default.conf

# Writable paths for www-data. storage/ and bootstrap/cache/ are excluded
# from the build context (.dockerignore) and arrive as a named volume at
# runtime, so create the baked-in skeletons first or chown fails.
RUN mkdir -p storage/app storage/framework/cache storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

# Never cache config at build time: the runtime .env is not present here,
# and a baked config cache would silently win over the VPS environment.
# The runbook (docs/saas/contabo-vps8-shared.md §6) caches config after
# first boot, from inside the running container.

EXPOSE 9000
CMD ["php-fpm"]
