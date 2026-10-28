<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Statuts biologiques d'une espèce sur un site Natura 2000 : le
     * formulaire standard (FSD) compte une ligne par statut (r :
     * reproduction, c : concentration, w : hivernage, p : passage) —
     * une même espèce pouvait donc apparaître jusqu'à 4 fois. On garde
     * une ligne unique par (site, espèce) et on fusionne les statuts
     * dans cette colonne (ex. « c,r,w »).
     */
    public function up(): void
    {
        Schema::table('inpn_natura_especes', function (Blueprint $table) {
            $table->string('presences', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inpn_natura_especes', function (Blueprint $table) {
            $table->dropColumn('presences');
        });
    }
};
