<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables du référentiel INPN (ZNIEFF, Natura 2000, aires protégées),
     * alimentées par `abc:import-inpn` puis exportées en SQL portable via
     * `abc:export-inpn` (la production n'a pas PostGIS : aucune géométrie
     * ici, uniquement des jointures par code INSEE de commune).
     *
     * Ces tables sont des FAITS BRUTS du référentiel INPN, pas des données
     * de l'Observatoire : aucun indicateur composite n'y est calculé. La
     * date de référence de chaque source est dans `inpn_imports`.
     */
    public function up(): void
    {
        Schema::create('inpn_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source')->unique();
            $table->string('version_source');
            $table->unsignedInteger('nb_entites');
            $table->timestamp('importe_le');
            $table->timestamps();
        });

        Schema::create('inpn_znieff', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); // ID_ZNIEFF (INPN)
            $table->string('id_national', 20)->unique(); // NM_SFFZN, ex. 240009732
            $table->unsignedTinyInteger('ty_zone'); // 1 ou 2
            $table->string('nom');
            $table->decimal('surface_ha', 12, 2)->nullable();
            $table->unsignedInteger('altitude_min')->nullable();
            $table->unsignedInteger('altitude_max')->nullable();
            $table->string('region_sig', 20)->nullable();
            $table->string('volet', 10)->nullable(); // CONT | MER
            $table->unsignedInteger('id_parent')->nullable();
            $table->text('texte_interet')->nullable(); // HTML INPN
            $table->text('texte_delimitation')->nullable();
            $table->text('texte_bilan')->nullable();
            $table->date('date_crea')->nullable();
            $table->date('date_modif')->nullable();
            $table->timestamps();

            $table->index('ty_zone');
        });

        Schema::create('inpn_znieff_communes', function (Blueprint $table) {
            $table->unsignedInteger('znieff_id');
            $table->string('code_commune', 5);
            $table->timestamps();

            $table->primary(['znieff_id', 'code_commune']);
            $table->foreign('znieff_id')->references('id')->on('inpn_znieff')->cascadeOnDelete();
            $table->index('code_commune');
        });

        Schema::create('inpn_znieff_especes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('znieff_id');
            $table->unsignedInteger('cd_ref'); // TAXREF
            $table->string('nom_cite');
            $table->string('groupe_taxo', 50)->nullable();
            $table->boolean('determinante')->default(false); // espèce déterminante
            $table->boolean('confidentielle')->default(false); // jamais affichée
            $table->timestamps();

            $table->foreign('znieff_id')->references('id')->on('inpn_znieff')->cascadeOnDelete();
            $table->index('znieff_id');
            $table->index('cd_ref');
        });

        Schema::create('inpn_natura_sites', function (Blueprint $table) {
            $table->string('sitecode', 9)->primary(); // ex. FR8212001
            $table->string('nom')->nullable(); // des_site (absent pour ~40 % des sites)
            $table->string('type_site', 5)->nullable(); // SIC (ZSC) | ZPS, libellé source
            $table->string('cd_sig', 40)->nullable();
            $table->string('echelle', 20)->nullable();
            $table->string('date_dern_modif', 20)->nullable(); // brut INPN, ex. « 07/2024 »

            $table->timestamps();
        });

        Schema::create('inpn_natura_communes', function (Blueprint $table) {
            $table->string('sitecode', 9);
            $table->string('code_commune', 5);
            // Champs bruts du référentiel INPN (regcode.csv), unités sources.
            $table->decimal('area_intersect', 12, 4)->nullable();
            $table->decimal('cover_intersect', 8, 4)->nullable();
            $table->string('lb_intersect', 10)->nullable();
            $table->timestamps();

            $table->primary(['sitecode', 'code_commune']);
            $table->foreign('sitecode')->references('sitecode')->on('inpn_natura_sites')->cascadeOnDelete();
            $table->index('code_commune');
        });

        Schema::create('inpn_natura_habitats', function (Blueprint $table) {
            $table->id();
            $table->string('sitecode', 9);
            $table->string('cd_ue', 10); // habitat directive, ex. 2110
            $table->string('cd_hab', 20)->nullable(); // Corine
            $table->decimal('cover', 12, 4)->nullable(); // champ FSD "cover", unité source
            $table->decimal('area', 12, 4)->nullable(); // champ FSD "area", unité source
            $table->char('representativite', 1)->nullable(); // A-D
            $table->char('conservation', 1)->nullable(); // A-D (global)
            $table->timestamps();

            $table->foreign('sitecode')->references('sitecode')->on('inpn_natura_sites')->cascadeOnDelete();
            $table->index('sitecode');
        });

        Schema::create('inpn_natura_especes', function (Blueprint $table) {
            $table->id();
            $table->string('sitecode', 9);
            $table->string('code_n2000', 10); // ex. A140 (annexe II directive)
            $table->unsignedInteger('cd_ref')->nullable();
            $table->string('nom_scientifique')->nullable();
            $table->string('nom_vernaculaire')->nullable();
            $table->string('taxgroup', 30)->nullable();
            $table->boolean('annexe_ii')->default(false);
            $table->boolean('prioritaire')->default(false);
            $table->timestamps();

            $table->foreign('sitecode')->references('sitecode')->on('inpn_natura_sites')->cascadeOnDelete();
            $table->index('sitecode');
            $table->index('code_n2000');
        });

        Schema::create('inpn_aires_protegees', function (Blueprint $table) {
            $table->string('id_mnhn', 20)->primary(); // ex. FR3800927
            $table->string('territoire', 10); // metrop | glp | reu | ...
            $table->string('nom');
            $table->string('type_espace'); // RNN, APB, site classé, CEN…
            $table->string('objectif_protection', 100)->nullable();
            $table->string('statut', 50)->nullable();
            $table->decimal('superficie_ha', 14, 4)->nullable();
            $table->string('cd_type_milieu', 10)->nullable();
            $table->string('lien_fiche')->nullable();
            $table->boolean('doc_gestion')->default(false);
            $table->date('date_crea_sign')->nullable();
            $table->date('date_modif_geo')->nullable();
            $table->timestamps();

            $table->index('territoire');
            $table->index('type_espace');
        });

        Schema::create('inpn_aires_protegees_communes', function (Blueprint $table) {
            $table->string('aire_protegee_id', 20);
            $table->string('code_commune', 5);
            // Recouvrement calculé à l'import (jointure spatiale locale avec
            // les polygones communaux Admin Express) : ordre de grandeur
            // décamétrique, à présenter comme tel à l'affichage.
            $table->decimal('surface_intersection_ha', 12, 4)->nullable();
            $table->timestamps();

            $table->primary(['aire_protegee_id', 'code_commune']);
            $table->foreign('aire_protegee_id')->references('id_mnhn')->on('inpn_aires_protegees')->cascadeOnDelete();
            $table->index('code_commune');
        });

        // FK auto-référente : après coup, sinon Postgres refuse (le PRIMARY
        // KEY non-auto de inpn_znieff n'existe pas encore au CREATE TABLE).
        Schema::table('inpn_znieff', function (Blueprint $table) {
            $table->foreign('id_parent')->references('id')->on('inpn_znieff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inpn_aires_protegees_communes');
        Schema::dropIfExists('inpn_aires_protegees');
        Schema::dropIfExists('inpn_natura_especes');
        Schema::dropIfExists('inpn_natura_habitats');
        Schema::dropIfExists('inpn_natura_communes');
        Schema::dropIfExists('inpn_natura_sites');
        Schema::dropIfExists('inpn_znieff_especes');
        Schema::dropIfExists('inpn_znieff_communes');
        Schema::dropIfExists('inpn_znieff');
        Schema::dropIfExists('inpn_imports');
    }
};
