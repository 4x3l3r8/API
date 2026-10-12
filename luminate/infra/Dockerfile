# Luminate build of Wellms. Build context is the repo root (see docker-compose.yml).
# Same steps as the repo's own Dockerfile, with the base image pinned by digest
# (escolalms/php:8.3-alpine as of 2026-10-12) and php-fpm told to keep the container
# environment, so configuration comes from plain env vars instead of a .env file.
FROM escolalms/php@sha256:a2ac1d99c023c7b0e830a462822292a79a00c8b87c50a020de126fff69b15e67
WORKDIR /var/www/html
EXPOSE 9000
COPY . /var/www/html
RUN cp docker/conf/supervisor/supervisord.conf /etc/supervisord.conf \
 && cp docker/conf/php/escolalms-custom-php.ini /usr/local/etc/php/conf.d/escolalms-custom-php.ini \
 && cp docker/conf/php/php-fpm-custom.conf /usr/local/etc/php-fpm.d/php-fpm-custom.conf \
 && cp luminate/infra/php-fpm-env.conf /usr/local/etc/php-fpm.d/zz-luminate-env.conf \
 && chmod +x init.sh
RUN composer install --no-scripts --no-dev --prefer-dist --no-interaction
CMD ["/var/www/html/init.sh"]
HEALTHCHECK --interval=30s --timeout=30s --start-period=60s --retries=5 CMD [ "php", "artisan", "health:check" ]
