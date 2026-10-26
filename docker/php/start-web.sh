#!/bin/sh
# Démarrage du serveur web applicatif (développement local Docker) :
# - php-fpm avec deux pools : www (site public) et api (/api/v1, workers
#   dédiés et limités) — un pic de trafic API ne peut pas priver le site
#   de ses process ;
# - nginx en avant-plan (port 8000) : fichiers statiques et routage des
#   requêtes vers le pool adéquat.
# Migrations/seed/import sont déjà faits par entrypoint.sh avant ce script.
set -e

mkdir -p /run/php
php-fpm -D

exec nginx -g 'daemon off;'
