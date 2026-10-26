FROM php:8.3-fpm-bookworm

# Dépendances système : libicu (intl), libpq (pgsql), libzip (zip), nginx
# (serveur web du conteneur applicatif : statiques + routage des pools).
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        nginx \
    && rm -rf /var/lib/apt/lists/*

# Extensions PHP exigées par le projet (pdo_pgsql, pgsql, intl, zip).
# pdo_sqlite / sqlite3 / mbstring / dom sont déjà inclus dans l'image officielle.
RUN docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Pools PHP-FPM : www (site public) + api (/api/v1, workers dédiés et
# limités — voir start-web.sh et docker/nginx/nginx.conf).
RUN rm -f /usr/local/etc/php-fpm.d/www.conf
COPY docker/php/pool-www.conf docker/php/pool-api.conf /usr/local/etc/php-fpm.d/

# nginx : les requêtes /api/v1/* partent sur le pool api, le reste sur www.
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf

# Script de démarrage (attente Postgres, migrate, seed, import legacy)
# puis lancement php-fpm + nginx.
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/php/start-web.sh /usr/local/bin/start-web.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/start-web.sh

WORKDIR /var/www/html

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/local/bin/start-web.sh"]
