<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projets', function (Blueprint $table) {
            $table->string('id', 1000)->primary();
            $table->text('nom');
            $table->string('structure_porteuse')->nullable();
            $table->string('type_de_structure_porteuse')->nullable();
            $table->integer('annee_debut')->nullable();
            $table->integer('annee_fin')->nullable();
            $table->string('avancement_raw')->nullable();
            $table->string('statut');
            $table->boolean('potentiellement_termine')->default(false);
            $table->boolean('potentiellement_en_cours')->default(false);
            $table->boolean('estime_termine')->default(false);
            $table->timestamp('statut_maj_at')->nullable();
            $table->boolean('ami_ofb')->nullable();
            $table->string('source');
            $table->string('url_page')->nullable();
            $table->timestamps();
        });

        Schema::create('communes', function (Blueprint $table) {
            $table->string('projet_id', 1000);
            $table->string('code_geographique');
            $table->string('libelle_geographique')->nullable();
            $table->string('epci')->nullable();
            $table->string('libelle_epci')->nullable();
            $table->string('departement')->nullable();
            $table->string('libelle_departement')->nullable();
            $table->string('region')->nullable();
            $table->string('libelle_pnr')->nullable();
            $table->double('lon')->nullable();
            $table->double('lat')->nullable();
            $table->boolean('anomalie')->default(false);
            $table->double('distance_centre_km')->nullable();
            $table->timestamps();

            $table->primary(['projet_id', 'code_geographique']);
            $table->foreign('projet_id')->references('id')->on('projets')->cascadeOnDelete();
            $table->index('libelle_departement');
            $table->index('region');
        });

        // Colonne géométrique prête pour les futures couches de carte.
        if (config('database.default') === 'pgsql') {
            DB::statement("SELECT AddGeometryColumn('communes', 'geom', 4326, 'POINT', 2)");
            DB::statement('CREATE INDEX communes_geom_idx ON communes USING GIST (geom)');
        }

        Schema::create('snapshots', function (Blueprint $table) {
            $table->date('snapshot_date');
            $table->string('projet_id', 1000);
            $table->string('avancement')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();

            $table->primary(['snapshot_date', 'projet_id']);
            $table->foreign('projet_id')->references('id')->on('projets')->cascadeOnDelete();
        });

        Schema::create('enrichissements', function (Blueprint $table) {
            $table->string('projet_id', 1000)->primary();
            $table->text('description')->nullable();
            $table->json('documents_json')->nullable();
            $table->timestamps();

            $table->foreign('projet_id')->references('id')->on('projets')->cascadeOnDelete();
        });

        Schema::create('verifications', function (Blueprint $table) {
            $table->string('projet_id', 1000)->primary();
            $table->string('etat')->default('a_verifier');
            $table->text('note')->nullable();
            $table->string('lien')->nullable();
            $table->timestamp('verifie_le')->nullable();
            $table->timestamps();

            $table->foreign('projet_id')->references('id')->on('projets')->cascadeOnDelete();
        });

        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->string('projet_id', 1000);
            $table->string('type');
            $table->json('payload_json');
            $table->string('commentaire')->nullable();
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('statut')->default('en_attente');
            $table->string('traite_par')->nullable();
            $table->timestamp('traite_le')->nullable();
            $table->text('note_admin')->nullable();
            $table->timestamps();

            $table->foreign('projet_id')->references('id')->on('projets')->cascadeOnDelete();
            $table->index('projet_id');
            $table->index('statut');
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contribution_id')->nullable();
            $table->string('action');
            $table->text('avant')->nullable();
            $table->text('apres')->nullable();
            $table->string('par_admin')->nullable();
            $table->timestamps();

            $table->foreign('contribution_id')->references('id')->on('contributions')->nullOnDelete();
        });

        Schema::create('actualites', function (Blueprint $table) {
            $table->id();
            $table->string('titre');
            $table->string('slug')->unique();
            $table->text('contenu');
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('statut')->default('publie');
            $table->timestamp('date_publication')->nullable();
            $table->timestamps();
        });

        Schema::create('geo_cache', function (Blueprint $table) {
            $table->string('code_geographique')->primary();
            $table->double('lon')->nullable();
            $table->double('lat')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_cache');
        Schema::dropIfExists('actualites');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('contributions');
        Schema::dropIfExists('verifications');
        Schema::dropIfExists('enrichissements');
        Schema::dropIfExists('snapshots');
        Schema::dropIfExists('communes');
        Schema::dropIfExists('projets');
    }
};
