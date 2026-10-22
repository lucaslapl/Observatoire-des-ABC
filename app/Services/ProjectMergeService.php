<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Enrichissement;
use App\Models\Financement;
use App\Models\ProjectAlias;
use App\Models\Projet;
use App\Models\Snapshot;
use App\Models\Verification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Fusionne deux projets dupliqués (ABC « Doublon suspect » du Fonds vert,
 * projets scindés du registre…) — extrait de abc:merge-projects pour être
 * aussi piloté depuis le panneau d'administration :
 *
 * - le doublon devient un alias du projet canonique (table project_aliases) ;
 * - communes et snapshots sont unis (dédupliqués sur le code INSEE / la
 *   date) ; en cas de conflit la valeur du canonique est conservée ;
 * - vérifications, contributions, financements et enrichissement migrent
 *   vers le canonique sans perte (notes concaténées si les deux en ont) ;
 * - le slug du doublon est conservé dans l'alias : /abc/{slug du doublon}
 *   redirige en 301 vers le canonique ;
 * - tout est journalisé dans audit_log, et un snapshot JSON pré-fusion
 *   (état complet du doublon) est écrit pour permettre le rollback.
 *
 * Aucune donnée n'est perdue : les valeurs du doublon non retenues
 * restent dans le snapshot pré-fusion et dans l'entrée d'audit.
 */
class ProjectMergeService
{
    public function __construct(private DoublonSuspectService $doublonsSuspects) {}

    /**
     * @return array{ok: bool, error?: string, stats?: array<string, mixed>}
     */
    public function merge(string $doublonId, string $canoniqueId, ?string $motif, string $origine, ?string $parAdmin): array
    {
        if ($doublonId === $canoniqueId) {
            return ['ok' => false, 'error' => 'Le doublon et le canonique sont identiques.'];
        }

        $doublon = Projet::find($doublonId);
        $canonique = Projet::find($canoniqueId);

        if (! $doublon) {
            return ['ok' => false, 'error' => "Projet doublon introuvable : {$doublonId}"];
        }
        if (! $canonique) {
            return ['ok' => false, 'error' => "Projet canonique introuvable : {$canoniqueId}"];
        }

        if (ProjectAlias::where('alias_project_id', $doublonId)->exists()) {
            return ['ok' => false, 'error' => "{$doublonId} est déjà un alias : refus de fusionner un alias."];
        }
        $aliasCanonique = ProjectAlias::where('alias_project_id', $canoniqueId)->first();
        if ($aliasCanonique) {
            return ['ok' => false, 'error' => "{$canoniqueId} est déjà un alias de {$aliasCanonique->project_id} : fusionner directement le canonique réel."];
        }

        $stats = DB::transaction(function () use ($doublon, $canonique, $motif, $origine, $parAdmin) {
            $snapshotPath = $this->writePreMergeSnapshot($doublon);

            $stats = [
                'communes_deplacees' => $this->mergeCommunes($doublon, $canonique),
                'snapshots_deplaces' => $this->mergeSnapshots($doublon, $canonique),
                'verification' => $this->mergeVerification($doublon, $canonique),
                'contributions_deplacees' => Contribution::where('projet_id', $doublon->id)->update(['projet_id' => $canonique->id]),
                'enrichissement' => $this->mergeEnrichissement($doublon, $canonique),
                'financements_deplaces' => Financement::where('project_id', $doublon->id)->update(['project_id' => $canonique->id]),
            ];

            ProjectAlias::create([
                'project_id' => $canonique->id,
                'alias_project_id' => $doublon->id,
                'alias_slug' => $doublon->slug,
                'source' => $doublon->source,
                'motif' => (string) ($motif ?? ''),
                'created_by' => $origine,
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
                    'motif' => $motif,
                ], JSON_UNESCAPED_UNICODE),
                'par_admin' => $parAdmin ?? $origine,
            ]);

            $stats['snapshot_rollback'] = $snapshotPath;

            return $stats;
        });

        return ['ok' => true, 'stats' => $stats];
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
     * Si les deux projets en ont une, l'état du canonique est conservé, la
     * note du doublon est concaténée, et les motifs automatiques (note_auto)
     * sont unis — dédoublonnés, et débarrassés des « Doublon suspect » que
     * la fusion vient justement de résoudre (le collecteur les recréera si
     * l'ambiguïté persiste avec d'autres homonymes).
     */
    private function mergeVerification(Projet $doublon, Projet $canonique): string
    {
        $verifDoublon = Verification::find($doublon->id);
        if (! $verifDoublon) {
            return 'rien à déplacer';
        }

        $verifCanonique = Verification::find($canonique->id);
        if (! $verifCanonique) {
            $verifDoublon->update([
                'projet_id' => $canonique->id,
                'note_auto' => $this->doublonsSuspects->nettoyer($verifDoublon->note_auto, [$doublon->id]),
            ]);

            return 'déplacée';
        }

        $note = trim(($verifCanonique->note ? $verifCanonique->note."\n" : '')
            .'[fusion de '.$doublon->id.'] '.trim((string) $verifDoublon->note));
        $noteAuto = $this->doublonsSuspects->nettoyer(
            trim(($verifCanonique->note_auto ? $verifCanonique->note_auto."\n" : '')
                .trim((string) $verifDoublon->note_auto)),
            [$doublon->id]
        );
        $verifCanonique->update([
            'note' => $note !== '' ? $note : null,
            'note_auto' => $noteAuto,
        ]);

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
            'financements' => Financement::where('project_id', $doublon->id)->get()->map(fn ($f) => $f->getAttributes())->all(),
            'enrichissement' => Enrichissement::where('projet_id', $doublon->id)->first()?->getAttributes(),
        ];

        $path = 'merges/'.now()->format('Ymd-His').'-'.sha1($doublon->id).'.json';
        Storage::disk('abc')->put($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $path;
    }
}
