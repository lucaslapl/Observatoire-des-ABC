<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Décisions humaines sur les groupes d'homonymes suspects : un admin a
 * tranché que les projets portant ce nom (normalisé) sont des ABC
 * distincts — « ce n'est pas un doublon ». Le collecteur (homonymies,
 * Fonds vert) s'appuie sur cette table pour ne pas recréer les notes
 * automatiques « Doublon suspect » déjà tranchées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doublon_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('nom_slug')->unique();
            $table->string('nom');
            $table->string('decision')->default('non_doublon');
            $table->string('motif')->nullable();
            $table->string('par_admin')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doublon_decisions');
    }
};
