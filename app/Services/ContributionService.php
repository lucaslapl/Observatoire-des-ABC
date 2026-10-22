<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\Projet;
use App\Models\Verification;
use Illuminate\Support\Carbon;

/**
 * Port de src/contributions.ts : application, refus et retrait de contributions,
 * dans la table verifications, avec journalisation dans audit_log.
 */
class ContributionService
{
    public const CONTRIBUTION_TYPES = ['statut', 'note', 'lien', 'autre', 'date_debut'];

    public function verificationEtatPourStatut(?string $s): ?string
    {
        return match ($s) {
            'termine' => 'confirme_termine',
            'en_cours' => 'confirme_en_cours',
            'va_debuter' => 'toujours_a_venir',
            default => null,
        };
    }

    /**
     * Réduit une contribution validée en nouvelles valeurs verifications.
     */
    public function verificationTarget(string $type, array $payload, ?array $avant): array
    {
        $etat = $avant['etat'] ?? 'a_verifier';
        $note = $avant['note'] ?? null;
        $lien = $avant['lien'] ?? null;

        if ($type === 'statut' && ! empty($payload['statut_suggere'])) {
            $etat = $this->verificationEtatPourStatut((string) $payload['statut_suggere']) ?? $etat;
        }

        if ($type === 'date_debut') {
            // Statut facultatif : « a débuté en avril 2026 » doit pouvoir être
            // signalé « en cours » sans attendre le recalcul automatique par
            // année, qui le laisse « va débuter » jusqu'au 1er janvier suivant.
            $etat = $this->verificationEtatPourStatut((string) ($payload['statut_suggere'] ?? '')) ?? 'confirme_date';
            $debut = $payload['annee_debut_suggeree'] ?? null;
            $fin = $payload['annee_fin_suggeree'] ?? null;
            // La ligne auto « Dates confirmées » d'une vérification antérieure
            // est remplacée, pas empilée.
            $note = $this->retirerLigneDates($note);
            if ($debut) {
                $ligne = $fin ? "{$debut}–{$fin}" : $debut;
                $note = $note ? "{$note}\n— Dates confirmées : {$ligne}" : "Dates confirmées : {$ligne}";
            }
            if (! empty($payload['source'])) {
                $note = $note ? "{$note} — Source : {$payload['source']}" : "Source : {$payload['source']}";
            }
        }

        if ($type === 'note' && ! empty($payload['note'])) {
            $note = $payload['note'];
        }

        if ($type === 'lien' && ! empty($payload['lien'])) {
            $lien = $payload['lien'];
        }

        if ($type === 'autre' && ! empty($payload['texte'])) {
            $note = $note ? "{$note}\n— {$payload['texte']}" : $payload['texte'];
        }

        return ['etat' => $etat, 'note' => $note, 'lien' => $lien];
    }

    /**
     * Retire la ligne auto « Dates confirmées : … » (avec son « — Source : … »
     * collé en fin) d'une note antérieure : une nouvelle suggestion de dates
     * la remplace au lieu de l'empiler.
     */
    private function retirerLigneDates(?string $note): ?string
    {
        if (! $note) {
            return null;
        }
        $restantes = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $note) ?: [],
            fn (string $ligne) => ! str_contains($ligne, 'Dates confirmées'),
        ));

        return $restantes === [] ? null : implode("\n", $restantes);
    }

    /**
     * Applique une contribution (statut en_attente -> validee).
     */
    public function applyContribution(int $id, string $admin): array
    {
        $c = Contribution::find($id);
        if (! $c) {
            return ['ok' => false, 'error' => 'Contribution introuvable'];
        }
        if ($c->statut !== 'en_attente') {
            return ['ok' => false, 'error' => 'Contribution déjà traitée'];
        }

        $avant = $this->projectStateOf($c->projet_id);
        $cible = $this->verificationTarget($c->type, $c->payload_json ?? [], $avant);

        $this->saveVerification($c->projet_id, $cible, $admin);

        if ($c->type === 'date_debut') {
            $payload = $c->payload_json ?? [];
            $payload = is_array($payload) ? $payload : (json_decode((string) $payload, true) ?: []);
            $debut = isset($payload['annee_debut_suggeree']) ? (int) $payload['annee_debut_suggeree'] : null;
            $fin = isset($payload['annee_fin_suggeree']) ? (int) $payload['annee_fin_suggeree'] : null;
            $debutInt = is_int($debut) ? $debut : null;
            if ($debutInt !== null || $fin !== null) {
                Projet::where('id', $c->projet_id)->update([
                    'annee_debut' => $debutInt,
                    'annee_fin' => $fin,
                ]);
            }
        }

        $apres = $this->projectStateOf($c->projet_id);
        $c->update(['statut' => 'validee', 'traite_par' => $admin, 'traite_le' => now()]);
        AuditLog::create([
            'contribution_id' => $id,
            'action' => 'validee',
            'avant' => json_encode($avant),
            'apres' => json_encode($apres),
            'par_admin' => $admin,
        ]);

        return ['ok' => true];
    }

    public function rejectContribution(int $id, string $admin, ?string $noteAdmin = null): array
    {
        $c = Contribution::find($id);
        if (! $c) {
            return ['ok' => false, 'error' => 'Contribution introuvable'];
        }
        if ($c->statut !== 'en_attente') {
            return ['ok' => false, 'error' => 'Contribution déjà traitée'];
        }

        $c->update([
            'statut' => 'refusee',
            'traite_par' => $admin,
            'traite_le' => now(),
            'note_admin' => $noteAdmin,
        ]);
        AuditLog::create([
            'contribution_id' => $id,
            'action' => 'refusee',
            'avant' => null,
            'apres' => null,
            'par_admin' => $admin,
        ]);

        return ['ok' => true];
    }

    /**
     * Annule une contribution (erreur d'envoi, erreur utilisateur) :
     * statut en_attente -> annulee, sans note de modération.
     */
    public function annulerContribution(int $id, string $admin): array
    {
        $c = Contribution::find($id);
        if (! $c) {
            return ['ok' => false, 'error' => 'Contribution introuvable'];
        }
        if ($c->statut !== 'en_attente') {
            return ['ok' => false, 'error' => 'Contribution déjà traitée'];
        }

        $c->update([
            'statut' => 'annulee',
            'traite_par' => $admin,
            'traite_le' => now(),
        ]);
        AuditLog::create([
            'contribution_id' => $id,
            'action' => 'annulee',
            'avant' => null,
            'apres' => null,
            'par_admin' => $admin,
        ]);

        return ['ok' => true];
    }

    public function revertContribution(int $id, string $admin): array
    {
        $c = Contribution::find($id);
        if (! $c) {
            return ['ok' => false, 'error' => 'Contribution introuvable'];
        }
        if ($c->statut !== 'validee') {
            return ['ok' => false, 'error' => 'Seule une contribution validée est réversible'];
        }

        $audit = AuditLog::where('contribution_id', $id)
            ->where('action', 'validee')
            ->latest('id')
            ->first();
        $avant = $audit && $audit->avant
            ? json_decode($audit->avant, true)
            : null;
        $etatAvant = $this->verificationStateOf($c->projet_id);

        if ($avant) {
            $this->applyState($c->projet_id, $avant);
        } else {
            Verification::where('projet_id', $c->projet_id)->delete();
        }

        if ($avant && (array_key_exists('annee_debut', $avant) || array_key_exists('annee_fin', $avant))) {
            Projet::where('id', $c->projet_id)->update([
                'annee_debut' => $avant['annee_debut'] ?? null,
                'annee_fin' => $avant['annee_fin'] ?? null,
            ]);
        }

        $c->update(['statut' => 'retiree', 'traite_par' => $admin, 'traite_le' => now()]);
        AuditLog::create([
            'contribution_id' => $id,
            'action' => 'retiree',
            'avant' => $etatAvant ? json_encode($etatAvant) : null,
            'apres' => $avant ? json_encode($avant) : null,
            'par_admin' => $admin,
        ]);

        return ['ok' => true];
    }

    protected function saveVerification(string $projetId, array $target, ?string $par = null): void
    {
        Verification::updateOrCreate(
            ['projet_id' => $projetId],
            [
                'etat' => $target['etat'],
                'note' => $target['note'],
                'lien' => $target['lien'],
                'verifie_le' => now(),
                'verifie_par' => $par,
            ],
        );
    }

    protected function applyState(string $projetId, array $state): void
    {
        $this->saveVerification($projetId, $state);
    }

    protected function verificationStateOf(string $projetId): ?array
    {
        $v = Verification::where('projet_id', $projetId)->first();

        return $v ? ['etat' => $v->etat, 'note' => $v->note, 'lien' => $v->lien] : null;
    }

    public function projectStateOf(string $projetId): array
    {
        $v = Verification::where('projet_id', $projetId)->first();
        $p = Projet::where('id', $projetId)->first();

        return [
            'etat' => $v->etat ?? 'a_verifier',
            'note' => $v->note ?? null,
            'lien' => $v->lien ?? null,
            'annee_debut' => $p ? (int) $p->annee_debut : null,
            'annee_fin' => $p ? (int) $p->annee_fin : null,
        ];
    }

    public static function payloadFromInput(array $input): array
    {
        $p = [];
        foreach (['statut_suggere', 'annee_debut_suggeree', 'annee_fin_suggeree', 'note', 'lien', 'texte', 'source'] as $key) {
            if (! empty($input[$key])) {
                $p[$key] = (string) $input[$key];
            }
        }

        return $p;
    }

    public function formatDate(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateTimeString() : null;
    }
}
