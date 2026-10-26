<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trace de l'acceptation explicite de la Licence Ouverte 2.0 par le
     * contributeur (case à cocher du formulaire de proposition de
     * correction) : c'est la base légale de la rediffusion des corrections
     * validées, notamment via l'API publique. Null = contribution antérieure
     * à la mise en place de la case (acceptation implicite) ou client ancien.
     */
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->boolean('licence_acceptee')->nullable()->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropColumn('licence_acceptee');
        });
    }
};
