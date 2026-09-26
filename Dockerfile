FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
    && docker-php-ext-install curl mbstring mysqli pdo pdo_mysql \
    && a2enmod headers rewrite \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

WORKDIR /var/www/html/

RUN mkdir -p storage/keys storage/uploads storage/backups \
    && chown -R www-data:www-data storage

RUN test -f /var/www/html/index.php \
    && test -f /var/www/html/includes/authentication.php \
    && test -f /var/www/html/modules/crad/index.php

ENV PORT=8000
# The first migration also loads the Class Schedule demo data; set SMS2_SEED_DEMO=0 on the host to skip it.
ENV SMS2_SEED_DEMO=1
EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:" . (getenv("PORT") ?: "8000") . "/up.php") === false ? 1 : 0);'

CMD ["sh", "-c", "if [ \"${SMS2_RUN_MIGRATIONS:-0}\" = \"1\" ]; then php /var/www/html/database/migrate.php; fi; sed -i \"s/^Listen .*/Listen ${PORT:-8000}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:[0-9]*>/<VirtualHost *:${PORT:-8000}>/\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
