<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Démographie par code INSEE (geo.api.gouv.fr, recensement INSEE) :
     * population en habitants, superficie en km². Durable dans geo_cache
     * (une valeur par code), dénormalisée sur communes (une valeur par
     * ligne projet×code) comme le sont déjà lon/lat.
     */
    public function up(): void
    {
        Schema::table('geo_cache', function (Blueprint $table) {
            $table->unsignedInteger('population')->nullable();
            $table->double('surface_km2')->nullable();
        });

        Schema::table('communes', function (Blueprint $table) {
            $table->unsignedInteger('population')->nullable();
            $table->double('surface_km2')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('communes', function (Blueprint $table) {
            $table->dropColumn(['population', 'surface_km2']);
        });

        Schema::table('geo_cache', function (Blueprint $table) {
            $table->dropColumn(['population', 'surface_km2']);
        });
    }
};
