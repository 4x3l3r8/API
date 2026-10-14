# Luminate build of Wellms. Build context is the repo root (see docker-compose.yml).
# Same steps as the repo's own Dockerfile, with the base image pinned by digest
# (escolalms/php:8.3-alpine as of 2026-10-12) and php-fpm told to keep the container
# environment, so configuration comes from plain env vars instead of a .env file.
FROM escolalms/php@sha256:a2ac1d99c023c7b0e830a462822292a79a00c8b87c50a020de126fff69b15e67
WORKDIR /var/www/html
EXPOSE 9000

# Fewer parallel connections: the 261 archives come from GitHub and a busy link makes bursts of connections time out
# (curl error 28), which aborts the whole install.
ENV COMPOSER_MAX_PARALLEL_HTTP=4

# 1. Wellms' own dependencies, exactly as locked. Only composer.json/composer.lock are copied here, so this slow layer is
#    rebuilt only when Wellms' dependencies change, never for add-on, script or config edits. The cache mount keeps every
#    downloaded archive between builds, so a failed or interrupted build resumes instead of starting over.
COPY composer.json composer.lock ./
# Retried as a whole: each attempt only downloads what the cache does not already hold, so a link that drops connections
# (curl error 28/56) still converges instead of failing the build after Composer's own few retries.
RUN --mount=type=cache,target=/root/.composer/cache,sharing=locked \
    for attempt in 1 2 3 4 5 6 7 8 9 10 11 12; do \
        composer install --no-scripts --no-dev --no-autoloader --prefer-dist --no-interaction && exit 0; \
        echo "composer install failed (attempt $attempt of 12), retrying in 10s"; sleep 10; \
    done; exit 1

# 2. The application source (vendor/ is excluded by Dockerfile.dockerignore, so step 1 is kept).
COPY . /var/www/html
RUN cp docker/conf/supervisor/supervisord.conf /etc/supervisord.conf \
 && cp docker/conf/php/escolalms-custom-php.ini /usr/local/etc/php/conf.d/escolalms-custom-php.ini \
 && cp docker/conf/php/php-fpm-custom.conf /usr/local/etc/php-fpm.d/php-fpm-custom.conf \
 && cp luminate/infra/php-fpm-env.conf /usr/local/etc/php-fpm.d/zz-luminate-env.conf \
 && chmod +x init.sh

# 3. Add-on packages are registered inside the image only, so the repo's composer.json/composer.lock stay
#    untouched (no core patch). A partial `update` keeps every other package at its locked version; it also builds the
#    autoloader. This layer is fast and is the only one that reruns when the add-on changes.
RUN --mount=type=cache,target=/root/.composer/cache,sharing=locked \
    composer config repositories.luminate-tenancy '{"type":"path","url":"luminate/addons/luminate-tenancy","options":{"symlink":false}}' \
 && composer require luminate/tenancy:@dev --no-update --no-interaction \
 && for attempt in 1 2 3 4 5 6; do \
        composer update luminate/tenancy --no-scripts --no-dev --prefer-dist --no-interaction --no-audit && exit 0; \
        echo "composer update failed (attempt $attempt of 6), retrying in 10s"; sleep 10; \
    done; exit 1

CMD ["/var/www/html/init.sh"]
HEALTHCHECK --interval=30s --timeout=30s --start-period=60s --retries=5 CMD [ "php", "artisan", "health:check" ]
