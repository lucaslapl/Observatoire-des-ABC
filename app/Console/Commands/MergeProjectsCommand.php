<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Enrichissement;
use App\Models\ProjectAlias;
use App\Models\Projet;
use App\Models\Snapshot;
use App\Models\Verification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Fusionne deux projets dupliqués (phase 1 de l'audit du 2026-10-21) :
 *
 * - le doublon devient un alias du projet canonique (table project_aliases) ;
 * - communes et snapshots sont unis (dédupliqués sur le code INSEE / la
 *   date) ; en cas de conflit la valeur du canonique est conservée ;
 * - vérifications, contributions et enrichissement migrent vers le
 *   canonique sans perte (notes concaténées si les deux en ont) ;
 * - le slug du doublon est conservé dans l'alias : /abc/{slug du doublon}
 *   redirige en 301 vers le canonique ;
 * - tout est journalisé dans audit_log, et un snapshot JSON pré-fusion
 *   (état complet du doublon) est écrit pour permettre le rollback.
 *
 * Aucune donnée n'est perdue : les valeurs du doublon non retenues
 * restent dans le snapshot pré-fusion et dans l'entrée d'audit.
 */
class MergeProjectsCommand extends Command
{
    protected $signature = 'abc:merge-projects
        {doublon : id du projet doublon (devient alias)}
        {canonique : id du projet canonique (conservé)}
        {--motif= : motif de la fusion (journalisé)}';

    protected $description = 'Fusionne un projet doublon dans un projet canonique (alias + union des données, sans perte)';

    public function handle(): int
    {
        /** @var string $doublonId */
        $doublonId = $this->argument('doublon');
        /** @var string $canoniqueId */
        $canoniqueId = $this->argument('canonique');

        if ($doublonId === $canoniqueId) {
            $this->error('Le doublon et le canonique sont identiques.');

            return self::FAILURE;
        }

        $doublon = Projet::find($doublonId);
        $canonique = Projet::find($canoniqueId);

        if (! $doublon) {
            $this->error("Projet doublon introuvable : {$doublonId}");

            return self::FAILURE;
        }
        if (! $canonique) {
            $this->error("Projet canonique introuvable : {$canoniqueId}");

            return self::FAILURE;
        }

        if (ProjectAlias::where('alias_project_id', $doublonId)->exists()) {
            $this->error("{$doublonId} est déjà un alias : refus de fusionner un alias.");

            return self::FAILURE;
        }
        $aliasCanonique = ProjectAlias::where('alias_project_id', $canoniqueId)->first();
        if ($aliasCanonique) {
            $this->error("{$canoniqueId} est déjà un alias de {$aliasCanonique->project_id} : fusionner directement le canonique réel.");

            return self::FAILURE;
        }

        $stats = DB::transaction(function () use ($doublon, $canonique) {
            $snapshotPath = $this->writePreMergeSnapshot($doublon);

            $stats = [
                'communes_deplacees' => $this->mergeCommunes($doublon, $canonique),
                'snapshots_deplaces' => $this->mergeSnapshots($doublon, $canonique),
                'verification' => $this->mergeVerification($doublon, $canonique),
                'contributions_deplacees' => Contribution::where('projet_id', $doublon->id)->update(['projet_id' => $canonique->id]),
                'enrichissement' => $this->mergeEnrichissement($doublon, $canonique),
            ];

            ProjectAlias::create([
                'project_id' => $canonique->id,
                'alias_project_id' => $doublon->id,
                'alias_slug' => $doublon->slug,
                'source' => $doublon->source,
                'motif' => (string) ($this->option('motif') ?? ''),
                'created_by' => 'abc:merge-projects',
            ]);

            $doublon->delete();

            AuditLog::create([
                'action' => 'fusion',
                'avant' => json_encode([
                    'doublon' => $doublon->only(['id', 'nom', 'slug', 'source', 'annee_debut', 'statut']),
                    'communes' => Commune::where('projet_id', $doublon->id)->count(),
                    'note' => 'état complet conservé dans '.$snapshotPath,
                ], JSON_UNESCAPED_UNICODE),
                'apres' => json_encode([
                    'canonique' => $canonique->only(['id', 'nom', 'slug', 'source']),
                    'stats' => $stats,
                    'motif' => $this->option('motif'),
                ], JSON_UNESCAPED_UNICODE),
                'par_admin' => 'abc:merge-projects',
            ]);

            $stats['snapshot_rollback'] = $snapshotPath;

            return $stats;
        });

        $this->info("Fusion {$doublonId} → {$canoniqueId} :");
        $this->line("  communes unies : +{$stats['communes_deplacees']}");
        $this->line("  snapshots unis : +{$stats['snapshots_deplaces']}");
        $this->line("  contributions migrées : {$stats['contributions_deplacees']}");
        $this->line("  vérification : {$stats['verification']}");
        $this->line("  enrichissement : {$stats['enrichissement']}");
        $this->line("  rollback possible : {$stats['snapshot_rollback']}");

        return self::SUCCESS;
    }

    /**
     * Union des communes : les codes INSEE du doublon absents du canonique
     * sont déplacés ; en cas de conflit la ligne du canonique est conservée.
     */
    private function mergeCommunes(Projet $doublon, Projet $canonique): int
    {
        $codesCanonique = Commune::where('projet_id', $canonique->id)->pluck('code_geographique')->all();

        return Commune::where('projet_id', $doublon->id)
            ->whereNotIn('code_geographique', $codesCanonique)
            ->update(['projet_id' => $canonique->id]);
    }

    /**
     * Union des snapshots : les dates du doublon absentes du canonique
     * sont déplacées ; en cas de conflit le snapshot canonique est conservé.
     */
    private function mergeSnapshots(Projet $doublon, Projet $canonique): int
    {
        $datesCanonique = Snapshot::where('projet_id', $canonique->id)->pluck('snapshot_date')->all();

        return Snapshot::where('projet_id', $doublon->id)
            ->whereNotIn('snapshot_date', $datesCanonique)
            ->update(['projet_id' => $canonique->id]);
    }

    /**
     * Migration de la vérification : le travail humain n'est jamais perdu.
     * Si les deux projets en ont une, l'état du canonique est conservé et
     * la note du doublon est concaténée.
     */
    private function mergeVerification(Projet $doublon, Projet $canonique): string
    {
        $verifDoublon = Verification::find($doublon->id);
        if (! $verifDoublon) {
            return 'rien à déplacer';
        }

        $verifCanonique = Verification::find($canonique->id);
        if (! $verifCanonique) {
            Verification::where('projet_id', $doublon->id)->update(['projet_id' => $canonique->id]);

            return 'déplacée';
        }

        $note = trim(($verifCanonique->note ? $verifCanonique->note."\n" : '')
            .'[fusion de '.$doublon->id.'] '.trim((string) $verifDoublon->note));
        $verifCanonique->update(['note' => $note !== '' ? $note : null]);

        $verifDoublon->delete();

        return 'unifiée (note du doublon concaténée)';
    }

    /**
     * Migration de l'enrichissement (id = projet_id) : si les deux en ont
     * un, les descriptions sont concaténées dans le canonique.
     */
    private function mergeEnrichissement(Projet $doublon, Projet $canonique): string
    {
        $enrichDoublon = Enrichissement::where('projet_id', $doublon->id)->first();
        if (! $enrichDoublon) {
            return 'rien à déplacer';
        }

        $enrichCanonique = Enrichissement::where('projet_id', $canonique->id)->first();
        if (! $enrichCanonique) {
            Enrichissement::where('projet_id', $doublon->id)->update(['projet_id' => $canonique->id]);

            return 'déplacé';
        }

        $enrichCanonique->update([
            'description' => trim(($enrichCanonique->description ? $enrichCanonique->description."\n" : '')
                .trim((string) $enrichDoublon->description)),
        ]);
        $enrichDoublon->delete();

        return 'unifié (descriptions concaténées)';
    }

    /**
     * Écrit l'état complet du doublon avant fusion (rollback possible : la
     * commande inverse n'existe pas encore, mais rien n'est perdu).
     *
     * @return string chemin relatif du snapshot (disque « abc »)
     */
    private function writePreMergeSnapshot(Projet $doublon): string
    {
        $payload = [
            'horodatage' => now()->toISOString(),
            'projet' => $doublon->getAttributes(),
            'communes' => Commune::where('projet_id', $doublon->id)->get()->map(fn ($c) => $c->getAttributes())->all(),
            'snapshots' => Snapshot::where('projet_id', $doublon->id)->get()->map(fn ($s) => $s->getAttributes())->all(),
            'verification' => Verification::find($doublon->id)?->getAttributes(),
            'contributions_ids' => Contribution::where('projet_id', $doublon->id)->pluck('id')->all(),
            'enrichissement' => Enrichissement::where('projet_id', $doublon->id)->first()?->getAttributes(),
        ];

        $path = 'merges/'.now()->format('Ymd-His').'-'.sha1($doublon->id).'.json';
        Storage::disk('abc')->put($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $path;
    }
}
