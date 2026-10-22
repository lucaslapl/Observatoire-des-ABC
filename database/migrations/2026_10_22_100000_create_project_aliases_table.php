<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table des alias de projets (fusions de la phase 1 de l'audit
     * du 2026-10-21). Un doublon fusionné devient un alias rattaché au
     * projet canonique : son id et son slug sont conservés pour que
     * les URLs historiques (/abc/{slug}) redirigent en 301 vers le
     * canonique, et pour que le collect ne le réintroduise pas.
     */
    public function up(): void
    {
        Schema::create('project_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('project_id', 1000);
            $table->string('alias_project_id', 1000)->unique();
            $table->string('alias_slug')->unique();
            $table->string('source')->nullable();
            $table->text('motif')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('project_id')->references('id')->on('projets')->cascadeOnDelete();
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_aliases');
    }
};
