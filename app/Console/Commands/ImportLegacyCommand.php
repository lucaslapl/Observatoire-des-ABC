<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Enrichissement;
use App\Models\GeoCache;
use App\Models\Projet;
use App\Models\Snapshot;
use App\Models\Verification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Import one-shot de l'ancienne base SQLite (data/abc.db) vers PostgreSQL.
 * Préserve les données humaines : verifications, contributions, audit_log,
 * ainsi que le cache géographique.
 */
class ImportLegacyCommand extends Command
{
    protected $signature = 'abc:import-legacy {--sqlite= : chemin de la base SQLite (défaut data/abc.db)}';

    protected $description = 'Importe les données de l\'ancienne base SQLite vers PostgreSQL';

    public function handle(): int
    {
        $path = $this->option('sqlite') ?? config('abc.legacy_db');
        if (! file_exists($path)) {
            $this->error("Base SQLite introuvable : {$path}");

            return self::FAILURE;
        }

        $pdo = new PDO("sqlite:{$path}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // L'import est idempotent : on repart d'une table vide (les FKs
        // cascadent sur les tables liées).
        AuditLog::query()->delete();
        Contribution::query()->delete();
        Verification::query()->delete();
        Enrichissement::query()->delete();
        Snapshot::query()->delete();
        Commune::query()->delete();
        Projet::query()->delete();
        GeoCache::query()->delete();

        $this->importProjets($pdo);
        $this->importCommunes($pdo);
        $this->importSnapshots($pdo);
        $this->importEnrichissements($pdo);
        $this->importVerifications($pdo);
        $this->importContributions($pdo);
        $this->importAuditLog($pdo);
        $this->importGeoCache();

        // Rétro-remplissage de la géométrie PostGIS à partir de lon/lat
        // (les anciennes communes n'avaient pas de colonne geom).
        $this->line('geometry…');
        $updated = DB::table('communes')
            ->whereNotNull('lon')
            ->where('lon', '!=', 0)
            ->where('lat', '!=', 0)
            ->update(['geom' => DB::raw('ST_SetSRID(ST_MakePoint(lon, lat), 4326)')]);
        $this->line("geometry : {$updated} communes");

        $this->info('Import terminé.');

        return self::SUCCESS;
    }

    private function importProjets(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT * FROM projets')->fetchAll(PDO::FETCH_ASSOC);
        $bar = $this->output->createProgressBar(count($rows));
        $this->line('projets…');

        foreach ($rows as $r) {
            Projet::updateOrCreate(
                ['id' => $r['id']],
                [
                    'nom' => $r['nom'],
                    'structure_porteuse' => $r['structure_porteuse'] ?: null,
                    'type_de_structure_porteuse' => $r['type_de_structure_porteuse'] ?: null,
                    'annee_debut' => $r['annee_debut'] !== null ? (int) $r['annee_debut'] : null,
                    'annee_fin' => $r['annee_fin'] !== null ? (int) $r['annee_fin'] : null,
                    'avancement_raw' => $r['avancement_raw'] ?: null,
                    'statut' => $r['statut'],
                    'potentiellement_termine' => (int) $r['potentiellement_termine'],
                    'potentiellement_en_cours' => (int) $r['potentiellement_en_cours'],
                    'estime_termine' => (int) $r['estime_termine'],
                    'statut_maj_at' => $r['statut_maj_at'] ?: null,
                    'ami_ofb' => $r['ami_ofb'] !== null ? (bool) $r['ami_ofb'] : null,
                    'source' => $r['source'],
                    'url_page' => $r['url_page'] ?: null,
                ],
            );
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    private function importCommunes(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT * FROM communes')->fetchAll(PDO::FETCH_ASSOC);
        $bar = $this->output->createProgressBar(count($rows));
        $this->line('communes…');

        $chunk = [];
        $seen = [];
        foreach ($rows as $r) {
            // L'ancienne base contenait des doublons (même couple projet x commune) :
            // on les déduplique pour respecter la PK composite.
            $key = $r['projet_id'].'|'.$r['code_geographique'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $chunk[] = [
                'projet_id' => $r['projet_id'],
                'code_geographique' => $r['code_geographique'],
                'libelle_geographique' => $r['libelle_geographique'] ?: null,
                'epci' => $r['epci'] ?: null,
                'libelle_epci' => $r['libelle_epci'] ?: null,
                'departement' => $r['departement'] ?: null,
                'libelle_departement' => $r['libelle_departement'] ?: null,
                'region' => $r['region'] ?: null,
                'libelle_pnr' => $r['libelle_pnr'] ?: null,
                'lon' => $r['lon'] !== null ? (float) $r['lon'] : null,
                'lat' => $r['lat'] !== null ? (float) $r['lat'] : null,
                'anomalie' => (int) $r['anomalie'],
                'distance_centre_km' => $r['distance_centre_km'] !== null ? (float) $r['distance_centre_km'] : null,
            ];
            if (count($chunk) >= 500) {
                Commune::insert($chunk);
                $chunk = [];
                $bar->advance(500);
            }
        }
        if ($chunk) {
            Commune::insert($chunk);
            $bar->advance(count($chunk));
        }
        $bar->finish();
        $this->newLine();
    }

    private function importSnapshots(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT * FROM snapshots')->fetchAll(PDO::FETCH_ASSOC);
        $bar = $this->output->createProgressBar(count($rows));
        $this->line('snapshots…');

        foreach ($rows as $r) {
            Snapshot::updateOrCreate(
                ['snapshot_date' => $r['snapshot_date'], 'projet_id' => $r['projet_id']],
                ['avancement' => $r['avancement'] ?: null, 'source' => $r['source'] ?: null],
            );
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    private function importEnrichissements(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT * FROM enrichissements')->fetchAll(PDO::FETCH_ASSOC);
        $bar = $this->output->createProgressBar(count($rows));
        $this->line('enrichissements…');

        foreach ($rows as $r) {
            Enrichissement::updateOrCreate(
                ['projet_id' => $r['projet_id']],
                [
                    'description' => $r['description'] ?: null,
                    'documents_json' => $r['documents_json'] ? json_decode($r['documents_json'], true) : null,
                ],
            );
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    private function importVerifications(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT * FROM verifications')->fetchAll(PDO::FETCH_ASSOC);
        $bar = $this->output->createProgressBar(count($rows));
        $this->line('verifications…');

        foreach ($rows as $r) {
            Verification::updateOrCreate(
                ['projet_id' => $r['projet_id']],
                [
                    'etat' => $r['etat'],
                    'note' => $r['note'] ?? null,
                    'lien' => $r['lien'] ?? null,
                    'verifie_le' => $r['verifie_le'] ?: null,
                ],
            );
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    private function importContributions(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT * FROM contributions')->fetchAll(PDO::FETCH_ASSOC);
        $bar = $this->output->createProgressBar(count($rows));
        $this->line('contributions…');

        foreach ($rows as $r) {
            Contribution::forceCreate([
                'id' => $r['id'],
                'projet_id' => $r['projet_id'],
                'type' => $r['type'],
                'payload_json' => json_decode($r['payload_json'], true),
                'commentaire' => $r['commentaire'] ?: null,
                'ip' => $r['ip'] ?: null,
                'user_agent' => $r['user_agent'] ?: null,
                'statut' => $r['statut'],
                'traite_par' => $r['traite_par'] ?: null,
                'traite_le' => $r['traite_le'] ?: null,
                'note_admin' => $r['note_admin'] ?: null,
            ]);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    private function importAuditLog(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT * FROM audit_log')->fetchAll(PDO::FETCH_ASSOC);
        $bar = $this->output->createProgressBar(count($rows));
        $this->line('audit_log…');

        foreach ($rows as $r) {
            AuditLog::create([
                'contribution_id' => $r['contribution_id'],
                'action' => $r['action'],
                'avant' => $r['avant'] ?: null,
                'apres' => $r['apres'] ?: null,
                'par_admin' => $r['par_admin'] ?: null,
            ]);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    /**
     * Réutilise le cache géo existant (data/cache/geo.json) pour ne pas
     * re-scraper ~5 000 communes.
     */
    private function importGeoCache(): void
    {
        $file = base_path('data/cache/geo.json');
        if (! file_exists($file)) {
            $this->warn('Cache géo absent, skip');

            return;
        }

        $cache = json_decode(file_get_contents($file), true) ?: [];
        $chunk = [];
        foreach ($cache as $code => $g) {
            $chunk[] = [
                'code_geographique' => $code,
                'lon' => $g['lon'] ?? null,
                'lat' => $g['lat'] ?? null,
                'name' => $g['name'] ?? null,
            ];
            if (count($chunk) >= 500) {
                GeoCache::upsert($chunk, ['code_geographique'], ['lon', 'lat', 'name']);
                $chunk = [];
            }
        }
        if ($chunk) {
            GeoCache::upsert($chunk, ['code_geographique'], ['lon', 'lat', 'name']);
        }
        $this->line('geo_cache importé : '.count($cache).' entrées');
    }
}
