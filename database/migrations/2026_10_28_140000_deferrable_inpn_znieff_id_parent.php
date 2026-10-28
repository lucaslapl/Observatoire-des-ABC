<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La contrainte auto-référente inpn_znieff.id_parent doit être
     * DEFERRABLE : l'import du dump portable (abc:import-sql) insère les
     * lignes dans un ordre non trié par identifiant — sans cela, une
     * ZNIEFF fille peut apparaître avant sa parente et violer la clé
     * étrangère. DEFERRABLE INITIALLY DEFERRED reporte la vérification
     * au COMMIT, où toutes les lignes existent.
     *
     * PostgreSQL uniquement : SQLite (tests) ne connaît pas la syntaxe,
     * et y vérifie déjà les clés au COMMIT dans une transaction.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! Schema::hasTable('inpn_znieff')) {
            return;
        }

        DB::statement('ALTER TABLE inpn_znieff DROP CONSTRAINT IF EXISTS inpn_znieff_id_parent_foreign');
        DB::statement(
            'ALTER TABLE inpn_znieff ADD CONSTRAINT inpn_znieff_id_parent_foreign'
            .' FOREIGN KEY (id_parent) REFERENCES inpn_znieff (id) ON DELETE SET NULL'
            .' DEFERRABLE INITIALLY DEFERRED'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! Schema::hasTable('inpn_znieff')) {
            return;
        }

        DB::statement('ALTER TABLE inpn_znieff DROP CONSTRAINT IF EXISTS inpn_znieff_id_parent_foreign');
        DB::statement(
            'ALTER TABLE inpn_znieff ADD CONSTRAINT inpn_znieff_id_parent_foreign'
            .' FOREIGN KEY (id_parent) REFERENCES inpn_znieff (id) ON DELETE SET NULL'
            .' NOT DEFERRABLE'
        );
    }
};
