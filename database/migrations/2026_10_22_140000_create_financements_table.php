<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table des financements Fonds vert (phase 2 de l'audit du 2026-10-21).
     *
     * Une ligne Fonds vert P113 est un FINANCEMENT accordé à un ABC, pas un
     * ABC distinct : elle ne crée jamais de projet, elle se rattache à un
     * projet existant. La ligne source complète est conservée en JSON
     * (ligne_brute). Unicité sur (project_id, source, ligne_id) : le même
     * dossier vu par deux collects n'est compté qu'une fois.
     */
    public function up(): void
    {
        Schema::create('financements', function (Blueprint $table) {
            $table->id();
            $table->string('project_id', 1000);
            $table->string('source');
            $table->string('ligne_id');
            $table->string('beneficiaire')->nullable();
            $table->unsignedInteger('annee_financement')->nullable();
            $table->decimal('montant', 14, 2)->nullable();
            $table->json('ligne_brute');
            $table->timestamps();

            $table->unique(['project_id', 'source', 'ligne_id']);
            $table->foreign('project_id')->references('id')->on('projets')->cascadeOnDelete();
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financements');
    }
};
