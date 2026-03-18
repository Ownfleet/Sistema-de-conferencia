FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN echo "=== MODS AVAILABLE ===" \
 && ls -la /etc/apache2/mods-available/ \
 && echo "=== MODS ENABLED ===" \
 && ls -la /etc/apache2/mods-enabled/ \
 && echo "=== MPM FILES ===" \
 && ls -la /etc/apache2/mods-enabled/*mpm* || true

WORKDIR /var/www/html
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

CMD ["/bin/bash", "-lc", "echo '=== APACHE MODULES ==='; apache2ctl -M; echo '=== APACHE CONFIG TEST ==='; apache2ctl configtest; apache2-foreground"]