<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Commune;
use Illuminate\Support\Facades\DB;

/**
 * Port de GET /api/verifications : liste des projets à vérifier avec leurs
 * motifs, communes et recherche associée.
 */
class VerificationService
{
    /**
     * @param  bool  $tous  true = inclut aussi les projets déjà vérifiés
     *                      (pour pouvoir les corriger depuis la page /verify)
     */
    public function list(bool $tous = false): array
    {
        $rows = DB::table('projets as p')
            ->leftJoin('verifications as v', 'v.projet_id', '=', 'p.id')
            ->select([
                'p.id',
                'p.nom',
                'p.structure_porteuse',
                'p.annee_debut',
                'p.statut',
                'p.source',
                'p.potentiellement_termine',
                'p.potentiellement_en_cours',
                'v.etat',
                'v.note',
                'v.note_auto',
                'v.lien',
                'v.verifie_le',
                'v.verifie_par',
            ])
            ->when(! $tous, fn ($q) => $q->where(fn ($w) => $w
                ->where('p.potentiellement_termine', true)
                ->orWhere('p.potentiellement_en_cours', true)
                ->orWhere('p.source', 'wayback')
                ->orWhereNull('p.annee_debut')
                // Explicitement mis en file de vérification (ex. paires de
                // doublons « à vérifier » du triage, financements ambigus).
                ->orWhere('v.etat', 'a_verifier')
                ->orWhereExists(function ($q2) {
                    $q2->select(DB::raw(1))
                        ->from('communes as c3')
                        ->whereColumn('c3.projet_id', 'p.id')
                        ->where('c3.anomalie', true);
                })))
            ->orderByRaw("(v.etat IS NULL OR v.etat = 'a_verifier') DESC, p.nom")
            ->get();

        $ids = $rows->pluck('id');
        $communes = Commune::whereIn('projet_id', $ids)->get(['projet_id', 'libelle_geographique', 'libelle_departement', 'anomalie']);
        $grouped = $communes->groupBy('projet_id');

        $projets = $rows->map(function ($p) use ($grouped) {
            $list = $grouped->get($p->id, collect());

            $motifs = [];
            if ($p->potentiellement_termine) {
                $motifs[] = 'potentiellement terminé';
            }
            if ($p->potentiellement_en_cours) {
                $motifs[] = 'potentiellement en cours';
            }
            if ($p->source === 'wayback') {
                $motifs[] = 'archives 2022';
            }
            if ($p->annee_debut === null) {
                $motifs[] = 'date inconnue';
            }

            $communesLibelles = $list->pluck('libelle_geographique')
                ->filter(fn ($v) => $v !== null)
                ->values();
            $communesAnormales = $list->where('anomalie', true)->pluck('libelle_geographique')
                ->filter(fn ($v) => $v !== null)
                ->values();
            $departements = $list->pluck('libelle_departement')
                ->filter(fn ($v) => $v !== null)
                ->unique()
                ->values();

            if ($communesAnormales->isNotEmpty()) {
                $motifs[] = 'anomalie';
            }

            $communesStr = $communesLibelles->implode(', ');
            $communesAnormalesStr = $communesAnormales->implode(', ');

            $place = trim(preg_replace('/ABC\s*/i', '', $p->structure_porteuse ?? $p->nom));
            $cible = trim($communesAnormalesStr !== '' ? $communesAnormalesStr : explode(',', $communesStr)[0]);
            $requete = trim("\"atlas de la biodiversité communale\" \"{$place}\" {$cible}");

            return [
                'id' => $p->id,
                'nom' => $p->nom,
                'structure_porteuse' => $p->structure_porteuse,
                'annee_debut' => $p->annee_debut,
                'statut' => $p->statut,
                'source' => $p->source,
                'motifs' => $motifs,
                'communes' => $communesStr,
                'departements' => $departements->implode(', '),
                'requete' => $requete,
                'etat' => $p->etat ?? 'a_verifier',
                'note' => $p->note,
                'note_auto' => $p->note_auto,
                'lien' => $p->lien,
                'verifie_le' => $p->verifie_le,
                'verifie_par' => $p->verifie_par,
            ];
        })->values();

        $compteurs = [];
        foreach ($projets as $p) {
            $compteurs[$p['etat']] = ($compteurs[$p['etat']] ?? 0) + 1;
        }

        return ['projets' => $projets->all(), 'compteurs' => $compteurs];
    }

    /**
     * Candidats Fonds vert non rattachés : lignes du collect passées en file
     * de vérification (audit_log, action worklist_fondsvert) qui ne sont
     * toujours pas rattachées à un projet — une ligne rattachée plus tard
     * (financement créé) disparaît automatiquement de cette liste.
     *
     * @return array<int, array{ligne_id: string, nom: string, beneficiaire: string|null, annee: int|null, montant: string|null, ambigu: array<int, string>|false}>
     */
    public function candidatsFondsVert(): array
    {
        $rattachees = DB::table('financements')->pluck('ligne_id')->all();

        return AuditLog::query()
            ->where('action', 'worklist_fondsvert')
            ->orderByDesc('created_at')
            ->get(['apres', 'created_at'])
            ->map(function ($log) {
                $payload = json_decode($log->apres, true) ?: [];

                return [
                    'ligne_id' => (string) ($payload['ligne_id'] ?? ''),
                    'nom' => (string) ($payload['nom'] ?? ''),
                    'beneficiaire' => $payload['beneficiaire'] ?? null,
                    'annee' => $payload['annee'] ?? null,
                    'montant' => $payload['ligne']['montant_engage'] ?? null,
                    'ambigu' => $payload['ambigu'] ?? false,
                ];
            })
            ->filter(fn ($c) => $c['nom'] !== '' && ! in_array($c['ligne_id'], $rattachees, true))
            ->values()
            ->all();
    }
}
