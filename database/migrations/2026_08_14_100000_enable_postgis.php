<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostGIS n'est pas disponible sur tous les hébergements (ex. Plesk
        // mutualisé). On ne l'active que s'il est réellement utilisable.
        if (config('database.default') === 'pgsql') {
            $available = DB::select("SELECT 1 FROM pg_available_extensions WHERE name = 'postgis'");
            if ($available) {
                DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
            }
        }
    }

    public function down(): void
    {
        // L'extension reste en place (la désactiver casserait d'autres tables).
    }
};
