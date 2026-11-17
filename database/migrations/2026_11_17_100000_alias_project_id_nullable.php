<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alias « même projet » : alias_project_id devient nullable.
 *
 * Historiquement, un alias venait TOUJOURS d'une fusion : alias_project_id
 * désignait l'ancien projet (doublon). L'audit SEO du 2026-11-17 (C2)
 * ajoute les alias de réparation de slugs (abc:fix:slugs) : le slug calculé
 * du nom — celui que les moteurs indexent — devient un alias 301 vers la
 * fiche quand le slug stocké diverge. Il n'y a alors aucun ancien projet :
 * alias_project_id est null.
 *
 * Le collect (ProjetRepository::isAliased) ne filtre que les ids strictement
 * égaux : null ne bloque jamais l'upsert d'un projet réel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_aliases', function (Blueprint $table) {
            $table->string('alias_project_id', 1000)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Les alias « même projet » (sans ancien doublon) sont des données
        // machine créées par abc:fix:slugs : elles disparaissent au retour
        // arrière, avant de rebloquer la colonne. Les aliases de fusion
        // (abc:merge-projects) gardent leur id de doublon : rien n'est perdu.
        DB::table('project_aliases')->whereNull('alias_project_id')->delete();

        Schema::table('project_aliases', function (Blueprint $table) {
            $table->string('alias_project_id', 1000)->nullable(false)->change();
        });
    }
};
