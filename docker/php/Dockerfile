FROM php:8.3-cli-bookworm

# Dépendances système : libicu (intl), libpq (pgsql), libzip (zip)
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libpq-dev \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Extensions PHP exigées par le projet (pdo_pgsql, pgsql, intl, zip).
# pdo_sqlite / sqlite3 / mbstring / dom sont déjà inclus dans l'image officielle.
RUN docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Script de démarrage (attente Postgres, migrate, seed, import legacy)
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
# --no-reload : indispensable en conteneur — sans lui, ServeCommand retire les
# variables d'environnement du sous-processus php -S (DB_HOST=... serait perdu
# au profit du .env bind-mounté) et Laravel lirait la config WAMP du poste hôte.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
