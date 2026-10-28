<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Annexe I de la directive Oiseaux pour les espèces des ZPS : les codes
     * « A### » des formulaires Natura 2000 sont des oiseaux, couverts par
     * ce référentiel (code_do_oiseaux) et non par la directive Habitats
     * (annexe II / « prioritaire »), qui ne les concerne pas.
     */
    public function up(): void
    {
        Schema::table('inpn_natura_especes', function (Blueprint $table) {
            $table->boolean('annexe_1')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('inpn_natura_especes', function (Blueprint $table) {
            $table->dropColumn('annexe_1');
        });
    }
};
