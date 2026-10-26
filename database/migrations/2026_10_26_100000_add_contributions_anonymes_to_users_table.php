<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Préférence d'affichage : le nom du contributeur n'apparaît plus
            // publiquement (accueil, historique des fiches) mais reste
            // accessible aux admins et modérateurs pour la modération.
            $table->boolean('contributions_anonymes')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('contributions_anonymes');
        });
    }
};
