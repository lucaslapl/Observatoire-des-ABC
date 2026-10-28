<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Habitats Natura 2000 : le référentiel de la directive
     * (dhff_habitats) donne le nom français de chaque habitat (code EUR,
     * annexe I) et son caractère prioritaire. Le champ « conserve » du
     * formulaire standard (structure et fonctions : A/B/C) est importé
     * dans une vraie colonne conservation — l'ancienne colonne
     * « conservation » contenait en réalité l'évaluation GLOBALE
     * (champ « global » du FSD), elle est renommée en conséquence.
     */
    public function up(): void
    {
        Schema::table('inpn_natura_habitats', function (Blueprint $table) {
            $table->renameColumn('conservation', 'evaluation_globale');
            $table->string('nom')->nullable()->after('cd_ue');
            $table->boolean('prioritaire')->default(false)->after('nom');
            $table->char('conservation', 1)->nullable()->after('representativite');
        });
    }

    public function down(): void
    {
        Schema::table('inpn_natura_habitats', function (Blueprint $table) {
            $table->dropColumn(['nom', 'prioritaire', 'conservation']);
            $table->renameColumn('evaluation_globale', 'conservation');
        });
    }
};
