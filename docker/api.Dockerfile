# Laravel API for production: PHP-FPM + nginx in one container (same pattern
# as StaceLib). serversideup images handle permissions, health checks, and
# (via AUTORUN) run `php artisan migrate --force` + config caching on boot.
# The worker/scheduler/reverb services reuse this image with AUTORUN off.
FROM serversideup/php:8.4-fpm-nginx

ENV AUTORUN_ENABLED=true \
    PHP_OPCACHE_ENABLE=1

USER root

COPY --chown=www-data:www-data api /var/www/html

RUN composer install --no-dev --optimize-autoloader --no-interaction

USER www-data
