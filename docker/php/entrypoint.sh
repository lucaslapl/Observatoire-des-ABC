#!/bin/sh
# Démarrage du conteneur applicatif (développement local Docker).
# 1. attend Postgres,
# 2. installe vendor/ si absent (volume dédié abc_vendor),
# 3. génère APP_KEY si absente,
# 4. lance migrate + seed (idempotents),
# 5. importe les données historiques (data/abc.db) une seule fois,
# 6. exécute la commande passée (défaut : php artisan serve).
set -e
cd /var/www/html

# --- Dépendances PHP (le volume nommé abc_vendor masque le vendor/ hôte) ---
if [ ! -f vendor/autoload.php ]; then
    echo "entrypoint: composer install..."
    composer install --no-interaction --prefer-dist --no-progress
fi

# --- APP_KEY manquante ? ---
if [ ! -f .env ] || ! grep -qE '^APP_KEY=[^[:space:]]+' .env; then
    echo "entrypoint: génération de APP_KEY..."
    php artisan key:generate --no-interaction
fi

# --- En dev Docker, le .env du poste hôte peut être utilisé via bind mount :
#     on écarte tout config/routes cache généré hors conteneur.
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear >/dev/null 2>&1 || true

# --- Attendre que Postgres réponde ---
echo "entrypoint: attente de la base de données..."
php -r '
$host = getenv("DB_HOST") ?: "postgis";
$port = getenv("DB_PORT") ?: "5432";
$db   = getenv("DB_DATABASE") ?: "abc";
$user = getenv("DB_USERNAME") ?: "abc";
$pass = getenv("DB_PASSWORD") ?: "abc";
$conn = "host={$host} port={$port} dbname={$db} user={$user} password={$pass} connect_timeout=3";
for ($i = 0; $i < 60; $i++) {
    $c = @pg_connect($conn);
    if ($c) { pg_close($c); exit(0); }
    sleep(2);
}
fwrite(STDERR, "Base de données injoignable après 120s.\n");
exit(1);
'

# --- Schéma + seed (rôles/permissions + admin depuis ADMIN_*) ---
php artisan migrate --force --graceful
php artisan db:seed --force

# --- Import historique une seule fois (base vide) ---
if [ -f data/abc.db ]; then
    count=$(php artisan tinker --execute='echo \App\Models\Projet::count();' 2>/dev/null | tail -n1 | tr -d "[:space:]")
    if [ "${count:-1}" = "0" ]; then
        echo "entrypoint: import des données historiques (abc:import-legacy)..."
        php artisan abc:import-legacy
    fi
fi

exec "$@"
