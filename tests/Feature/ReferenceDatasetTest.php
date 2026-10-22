<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\Projet;
use App\Models\Snapshot;
use App\Models\Verification;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test de référence (phase 0 de l'audit du 2026-10-21) :
 *
 * 1. l'import historique (data/abc.db, versionné) produit une base exploitable
 *    avec des compteurs stables — toute dérive détectée ici signale soit un
 *    changement du jeu de données de référence, soit une régression ;
 * 2. aucun collect automatique n'est planifié tant que COLLECT_AUTOMATIC
 *    vaut false (défaut), garantissant qu'un environnement local ne peut
 *    pas déclencher un abc:collect par le scheduler.
 */
class ReferenceDatasetTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Compteurs attendus après `abc:import-legacy` sur data/abc.db.
     * Toute modification volontaire du jeu de référence doit les mettre
     * à jour (et être justifiée dans le CHANGELOG).
     */
    private const COMPTEURS_REFERENCE = [
        'projets' => 1338,
        'communes' => 5437,
        'snapshots' => 1958,
        'verifications' => 5,
        'contributions' => 1,
    ];

    public function test_import_legacy_produit_les_compteurs_de_reference(): void
    {
        $this->assertFileExists(config('abc.legacy_db'), 'data/abc.db absent : impossible de constituer la base de référence');

        $this->artisan('abc:import-legacy')->assertExitCode(0);

        $this->assertSame(
            self::COMPTEURS_REFERENCE['projets'],
            Projet::count(),
            'projets après import de référence'
        );
        $this->assertSame(
            self::COMPTEURS_REFERENCE['communes'],
            DB::table('communes')->count(),
            'lignes projet-commune après import de référence'
        );
        $this->assertSame(
            self::COMPTEURS_REFERENCE['snapshots'],
            Snapshot::count(),
            'snapshots après import de référence'
        );
        $this->assertSame(
            self::COMPTEURS_REFERENCE['verifications'],
            Verification::count(),
            'verifications après import de référence'
        );
        $this->assertSame(
            self::COMPTEURS_REFERENCE['contributions'],
            Contribution::count(),
            'contributions après import de référence'
        );
    }

    public function test_aucun_collect_planifie_tant_que_collect_automatic_est_desactive(): void
    {
        // Défaut : config('abc.collect_automatic') = env('COLLECT_AUTOMATIC', false)
        $this->assertFalse(config('abc.collect_automatic'), 'COLLECT_AUTOMATIC doit valoir false par défaut');

        Artisan::call('schedule:list');
        $planning = Artisan::output();

        // Sanity : le planning est bien chargé (les tâches de fond attendues
        // y figurent)…
        $this->assertStringContainsString('abc:backup', $planning);
        $this->assertStringContainsString('ssr:watchdog', $planning);
        // …mais jamais le collect tant que le flag est désactivé.
        $this->assertStringNotContainsString('abc:collect', $planning, 'abc:collect ne doit pas être planifié quand COLLECT_AUTOMATIC=false');
    }
}
