<?php

namespace Tests\Feature;

use App\Collectors\FondsVertCollector;
use App\Models\AuditLog;
use App\Models\Financement;
use App\Models\ProjectAlias;
use App\Models\Projet;
use App\Models\Verification;
use App\Services\CollectService;
use App\Services\ProjectIdService;
use App\Services\ProjetRepository;
use App\Services\VerificationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Phase 2 de l'audit du 2026-10-21 : une ligne Fonds vert est un financement
 * accordé à un ABC existant, jamais un ABC distinct. Le collector ne crée
 * aucun projet : il rattache (id exact, alias de fusion, nom normalisé
 * unique) ou met en file de vérification (ambigu / nouveau).
 */
class FondsVertCollectorTest extends TestCase
{
    use DatabaseMigrations;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir().'/abc-fv-test-'.uniqid();
        mkdir($this->cacheDir, 0777, true);
        // Sources pinnées (pas d'appel API) + cache local des CSV.
        config(['abc.cache_dir' => $this->cacheDir, 'abc.auto_resolve_sources' => false]);

        // Fichier 2024 vide de candidats ABC (une ligne non-ABC, assez longue
        // pour la garde anti-HTML de DownloadService).
        file_put_contents($this->cacheDir.'/fonds-vert-p113-2024-export.csv',
            "nom_du_projet,resume_du_projet,montant_engage,numero_dossier_ds,raison_sociale_beneficiaire,demarche\n".
            "\"Renaturation du parking\",\"Un projet de voirie sans rapport avec les atlas, mentionné ici pour remplir le fichier de test avec suffisamment d'octets et vérifier la robustesse du lecteur CSV.\",120000.00,111,OFS TEST,Renaturation\n"
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cacheDir.'/*') ?: []);
        rmdir($this->cacheDir);
        parent::tearDown();
    }

    private function ligneFv2025(string $nom, string $benef, string $dossier, string $montant = '150000.00'): void
    {
        file_put_contents($this->cacheDir.'/fonds-vert-p113-2025-export.csv.csv',
            "nom_du_projet,resume_du_projet,montant_engage,numero_dossier_ds,raison_sociale_beneficiaire,demarche\n".
            "\"{$nom}\",\"L'atlas de la biodiversité communale de la collectivité est financé par le Fonds vert.\",{$montant},{$dossier},\"{$benef}\",Biodiversité\n"
        );
    }

    private function collect(): array
    {
        ProjetRepository::resetIngested();

        return app(FondsVertCollector::class)->collect();
    }

    public function test_ligne_correspondant_a_un_projet_existant_rattache_le_financement(): void
    {
        $ids = app(ProjectIdService::class);
        $idFv = $ids->projetId('ABC de Fondvert', 'COMMUNE DE FONDVERT', 2025);
        Projet::create([
            'id' => $idFv,
            'nom' => 'ABC de Fondvert',
            'structure_porteuse' => 'COMMUNE DE FONDVERT',
            'statut' => 'a_venir',
            'source' => 'fondsvert-p113-2025',
        ]);

        $this->ligneFv2025('ABC de Fondvert', 'COMMUNE DE FONDVERT', 11314742);
        $result = $this->collect();

        // Aucun projet créé, un financement rattaché, projet vu dans la source.
        $this->assertSame(1, Projet::count());
        $this->assertSame(1, $result['rattaches']);
        $this->assertSame(0, $result['en_worklist']);
        $this->assertSame(1, Financement::count());
        $this->assertDatabaseHas('financements', [
            'project_id' => $idFv,
            'source' => 'fondsvert-p113-2025',
            'ligne_id' => '11314742',
            'beneficiaire' => 'COMMUNE DE FONDVERT',
            'annee_financement' => 2025,
            'montant' => '150000.00',
        ]);
        $this->assertContains($idFv, ProjetRepository::ingestedIds());
        $this->assertSame('Biodiversité', Financement::first()->ligne_brute['demarche']);
    }

    public function test_ligne_ayant_un_doublon_fusionne_rattache_au_canonique(): void
    {
        $ids = app(ProjectIdService::class);
        $idFv = $ids->projetId('ABC de Fondvert', 'COMMUNE DE FONDVERT', 2025);

        $canonique = Projet::create([
            'id' => 'abc-de-fondvert-commune-de-fondvert-2024',
            'nom' => 'ABC de Fondvert',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        ProjectAlias::create([
            'project_id' => $canonique->id,
            'alias_project_id' => $idFv,
            'alias_slug' => 'abc-de-fondvert',
            'source' => 'fondsvert-p113-2025',
            'created_by' => 'test',
        ]);

        $this->ligneFv2025('ABC de Fondvert', 'COMMUNE DE FONDVERT', 11314743);
        $result = $this->collect();

        $this->assertSame(1, Projet::count());
        $this->assertSame(1, $result['rattaches']);
        $this->assertDatabaseHas('financements', [
            'project_id' => $canonique->id,
            'ligne_id' => '11314743',
        ]);
        $this->assertContains($canonique->id, ProjetRepository::ingestedIds());
    }

    public function test_nom_normalise_unique_rattache_aussi(): void
    {
        Projet::create([
            'id' => 'abc-de-fondvert-fondvert-2024',
            'nom' => 'ABC de Fondvert',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2024,
        ]);

        // Même nom normalisé, clé d'identité différente (structure/année).
        $this->ligneFv2025('ABC de Fondvert', 'COMMUNE DE FONDVERT', 11314744);
        $result = $this->collect();

        $this->assertSame(1, $result['rattaches']);
        $this->assertSame(1, Financement::count());
        $this->assertDatabaseHas('financements', ['project_id' => 'abc-de-fondvert-fondvert-2024']);
    }

    public function test_ligne_nouvelle_passe_en_worklist_sans_creer_de_projet(): void
    {
        $this->ligneFv2025('ABC de Nouveau Territoire', 'COMMUNE DE NOUVEAU', 11314745);

        $result = $this->collect();

        $this->assertSame(0, Projet::count(), 'aucun projet ne doit être créé');
        $this->assertSame(0, Financement::count());
        $this->assertSame(1, $result['en_worklist']);

        $entry = AuditLog::where('action', 'worklist_fondsvert')->first();
        $this->assertNotNull($entry);
        $payload = json_decode($entry->apres, true);
        $this->assertSame('11314745', $payload['ligne_id']);
        $this->assertFalse($payload['ambigu']);
        $this->assertSame('ABC de Nouveau Territoire', $payload['nom']);
    }

    public function test_ligne_ambigue_ne_cree_rien_et_file_les_candidats(): void
    {
        // Deux projets portant le même nom normalisé (éditions distinctes).
        Projet::create(['id' => 'abc-d-ambigu-a-2020', 'nom' => "ABC d'Ambigu", 'statut' => 'termine', 'source' => 'data.gouv', 'annee_debut' => 2020]);
        Projet::create(['id' => 'abc-d-ambigu-b-2024', 'nom' => "ABC d'Ambigu", 'statut' => 'en_cours', 'source' => 'data.gouv', 'annee_debut' => 2024]);

        $this->ligneFv2025("ABC d'Ambigu", 'COMMUNE D\'AMBIGU', 11314746);
        $result = $this->collect();

        $this->assertSame(2, Projet::count(), 'aucun projet ne doit être créé');
        $this->assertSame(0, Financement::count(), 'aucun rattachement silencieux');
        $this->assertSame(1, $result['en_worklist']);

        // Les deux candidats sont en file /verify, le motif automatique dans
        // note_auto — jamais dans la note humaine (verdicts, GeoJSON).
        $this->assertSame(2, Verification::where('etat', 'a_verifier')->count());
        $this->assertStringContainsString('Fonds vert P113 2025 ligne 11314746', (string) Verification::first()->note_auto);
        $this->assertNull(Verification::first()->note);

        $payload = json_decode(AuditLog::where('action', 'worklist_fondsvert')->first()->apres, true);
        $this->assertCount(2, $payload['ambigu']);
    }

    public function test_le_collect_est_idempotent(): void
    {
        Projet::create([
            'id' => app(ProjectIdService::class)->projetId('ABC de Fondvert', 'COMMUNE DE FONDVERT', 2025),
            'nom' => 'ABC de Fondvert',
            'statut' => 'a_venir',
            'source' => 'fondsvert-p113-2025',
        ]);
        $this->ligneFv2025('ABC de Fondvert', 'COMMUNE DE FONDVERT', 11314747);
        $this->ligneFv2025('ABC de Fondvert', 'COMMUNE DE FONDVERT', 11314747);

        $this->collect();
        $this->collect();

        $this->assertSame(1, Financement::count(), 'la même ligne n\'est comptée qu\'une fois');
        $this->assertSame(1, Projet::count());
    }

    public function test_ligne_atlas_de_la_biodiversite_communale_est_un_candidat(): void
    {
        // Régression du correctif d'accents : sans normalisation NFC ni le
        // drapeau /u, « biodiversit[ée] » ne matchait jamais le « é » UTF-8
        // composé (ni décomposé) et ces lignes étaient invisibles du filtre.
        $nfc = 'Atlas de la biodiversité communale de Fondvert';
        $nfd = \Normalizer::normalize($nfc, \Normalizer::FORM_D);

        file_put_contents($this->cacheDir.'/fonds-vert-p113-2025-export.csv.csv',
            "nom_du_projet,resume_du_projet,montant_engage,numero_dossier_ds,raison_sociale_beneficiaire,demarche\n".
            "\"{$nfc}\",\"Un atlas de la biodiversité communale financé par le Fonds vert, décrit en UTF-8 composé pour le test.\",100000.00,11314748,\"COMMUNE DE FONDVERT\",Biodiversité\n".
            "\"{$nfd}\",\"Un atlas de la biodiversité communale financé par le Fonds vert, décrit en UTF-8 décomposé pour le test.\",100000.00,11314749,\"COMMUNE DE FONDVERT\",Biodiversité\n"
        );

        $result = $this->collect();

        // Les deux formes (NFC et NFD) sont des candidats, et aucune
        // correspondance n'existe : tout part en worklist, zéro création.
        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['en_worklist']);
        $this->assertSame(0, Projet::count());
    }

    public function test_les_candidats_fonds_vert_apparaissent_dans_la_page_verify(): void
    {
        $this->ligneFv2025('ABC de Nouveau Territoire', 'COMMUNE DE NOUVEAU', 11314750);
        $this->collect();

        $response = $this->get('/verify');
        $response->assertOk();
        $candidats = collect($response->inertiaProps()['candidats'] ?? []);
        $this->assertTrue($candidats->pluck('nom')->contains('ABC de Nouveau Territoire'),
            'le candidat Fonds vert doit apparaître dans /verify');
    }

    public function test_un_candidat_rattache_disparait_de_la_worklist(): void
    {
        $this->ligneFv2025('ABC de Fondvert', 'COMMUNE DE FONDVERT', 11314751);
        $this->collect();
        $this->assertSame(1, count(app(VerificationService::class)->candidatsFondsVert()));

        // Le projet apparaît ensuite (création manuelle validée par un humain).
        Projet::create([
            'id' => app(ProjectIdService::class)->projetId('ABC de Fondvert', 'COMMUNE DE FONDVERT', 2025),
            'nom' => 'ABC de Fondvert',
            'statut' => 'a_venir',
            'source' => 'data.gouv',
        ]);
        $this->collect();

        $this->assertSame(1, Financement::count());
        $this->assertSame(0, count(app(VerificationService::class)->candidatsFondsVert()),
            'le candidat rattaché ne doit plus apparaître dans la worklist');
    }

    public function test_un_projet_finance_nest_pas_supprime_comme_stale(): void
    {
        $projet = Projet::create(['id' => 'projet-finance', 'nom' => 'ABC Financé', 'statut' => 'en_cours', 'source' => 'data.gouv']);
        Financement::create([
            'project_id' => $projet->id,
            'source' => 'fondsvert-p113-2025',
            'ligne_id' => '999',
            'annee_financement' => 2025,
            'ligne_brute' => ['nom_du_projet' => 'ABC Financé'],
        ]);

        // Vu nulle part, sans travail humain — mais financé.
        $deleted = app(CollectService::class)->deleteStaleProjects(['projet-autre']);

        $this->assertSame(0, $deleted);
        $this->assertDatabaseHas('projets', ['id' => 'projet-finance']);
    }

    public function test_le_collect_nettoie_les_doublons_suspects_resolus(): void
    {
        // Ambiguïté résolue depuis (fusion, homonyme supprimé) : la note
        // automatique est périmée — et dupliquée ici.
        $projet = Projet::create([
            'id' => 'abc-de-test-fusion-2021',
            'nom' => 'ABC de Test Fusion',
            'slug' => 'abc-de-test-fusion',
            'statut' => 'en_cours',
            'source' => 'fondsvert-p113-2025',
        ]);
        $note = 'Doublon suspect (Fonds vert P113 2024 ligne fv-x) : « ABC de Test Fusion » semble financer ce projet, mais d\'autres projets portent le même nom.';
        Verification::create([
            'projet_id' => $projet->id,
            'etat' => 'a_verifier',
            'note_auto' => $note."\n".$note,
        ]);

        file_put_contents($this->cacheDir.'/fonds-vert-p113-2025-export.csv.csv',
            "nom_du_projet,resume_du_projet,montant_engage,numero_dossier_ds,raison_sociale_beneficiaire,demarche\n".
            "\"Renaturation du parking\",\"Un projet de voirie sans rapport avec les atlas, mentionné ici pour remplir le fichier de test avec suffisamment d'octets et vérifier la robustesse du lecteur CSV.\",120000.00,112,OFS TEST,Renaturation\n"
        );

        $result = $this->collect();

        $this->assertSame(1, $result['notes_doublon_nettoyees']);
        $this->assertNull(Verification::find($projet->id)->note_auto);
    }
}
