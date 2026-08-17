<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Liste noire des projets jugés erronés et supprimés : le collect ne
        // les ré-importe pas tant que leur exclusion n'est pas levée.
        Schema::create('project_exclusions', function (Blueprint $table) {
            $table->string('projet_id', 1000)->primary();
            $table->string('motif')->nullable();
            $table->string('par_admin')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_exclusions');
    }
};
