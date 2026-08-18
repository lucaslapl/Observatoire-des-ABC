<?php

use App\Models\Projet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-réparation de `projets.slug`.
 *
 * Sur certains hébergements (Plesk provisionné via abc:export-deploy) la
 * colonne `slug` n'a jamais été ajoutée, ou est restée vide : les routes
 * /abc/{slug} et le sitemap renvoient alors une erreur 500 et les pages
 * de landing génèrent des liens /abc/null.
 *
 * Cette migration est idempotente : elle ajoute la colonne si elle manque,
 * recrée l'index unique le cas échéant, puis rétro-remplit tous les slugs
 * nuls/vides. Elle peut être jouée en toute sécurité même si la migration
 * 2026_08_17_100003 est déjà enregistrée dans la table `migrations`.
 */
return new class extends Migration
{
    /** Vrai si cette migration a elle-même créé la colonne (à nettoyer en down()). */
    private bool $addedColumn = false;

    public function up(): void
    {
        if (! Schema::hasTable('projets')) {
            return;
        }

        if (! Schema::hasColumn('projets', 'slug')) {
            Schema::table('projets', function (Blueprint $table) {
                $table->string('slug', 255)->nullable()->unique()->after('id');
            });
            $this->addedColumn = true;
        }

        $this->backfill();
    }

    public function down(): void
    {
        // Ne retire la colonne que si cette migration l'a créée. Sinon elle
        // provient de la migration 2026_08_17_100003, dont le down() s'en
        // charge — évite le double drop de l'index en rollback (RefreshDatabase).
        if (! $this->addedColumn) {
            return;
        }

        Schema::table('projets', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }

    private function backfill(): void
    {
        $projets = Projet::query()->whereNull('slug')->get(['id', 'nom']);

        foreach ($projets as $projet) {
            $slug = Projet::makeUniqueSlug($projet->nom, $projet->id);
            Projet::query()->whereKey($projet->id)->update(['slug' => $slug]);
        }
    }
};
