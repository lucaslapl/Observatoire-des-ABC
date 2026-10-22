<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Projet;
use App\Models\Verification;
use App\Services\ContributionService;
use App\Services\StatusService;
use App\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VerificationController extends Controller
{
    public function show(VerificationService $service): array
    {
        return $service->list();
    }

    public function store(Request $request, StatusService $status): JsonResponse
    {
        $validated = $request->validate([
            'projet_id' => ['required', 'string'],
            'etat' => ['required', 'string'],
            'note' => ['nullable', 'string'],
            'lien' => ['nullable', 'string'],
            'annee_debut' => ['nullable', 'integer'],
            'annee_fin' => ['nullable', 'integer'],
            'statut_suggere' => ['nullable', Rule::in(['termine', 'en_cours', 'va_debuter'])],
        ]);

        // Correction de date avec statut constaté : le verdict devient celui
        // du statut observé (affiché sur la fiche et la carte via
        // statutDepuisVerification) au lieu de laisser le statut calculé.
        $etat = $validated['etat'];
        $statutConstate = $validated['statut_suggere'] ?? null;
        if ($etat === 'confirme_date' && $statutConstate !== null) {
            $etat = $status->verificationEtatPourStatut($statutConstate) ?? $etat;
        }

        // Un verdict de correction de date porte la ligne « Dates confirmées »
        // dans la note, comme les contributions validées : même affichage sur
        // la fiche, et même protection contre l'écrasement par le collect
        // (ProjetRepository). Une ligne antérieure est remplacée.
        $note = $validated['note'] ?? null;
        $debut = $validated['annee_debut'] ?? null;
        $fin = $validated['annee_fin'] ?? null;
        if ($validated['etat'] === 'confirme_date' && $debut !== null) {
            $note = ContributionService::retirerLigneDates($note);
            $ligne = $fin ? "{$debut}–{$fin}" : (string) $debut;
            $note = $note !== null ? "{$note}\n— Dates confirmées : {$ligne}" : "Dates confirmées : {$ligne}";
        }

        $previous = Verification::find($validated['projet_id']);
        $avant = $previous ? [
            'projet_id' => $previous->projet_id,
            'etat' => $previous->etat,
            'note' => $previous->note,
            'lien' => $previous->lien,
            'verifie_par' => $previous->verifie_par,
        ] : null;
        $user = $request->user();

        Verification::updateOrCreate(
            ['projet_id' => $validated['projet_id']],
            [
                'etat' => $etat,
                'note' => $note,
                'lien' => $validated['lien'] ?? null,
                'verifie_le' => now(),
                'verifie_par' => $user->name,
            ],
        );

        // Les années ne sont envoyées que pour une correction de date : l'année
        // de début seule suffit (l'année de fin absente est alors effacée,
        // même pipeline que les suggestions de type date_debut).
        if ($validated['etat'] === 'confirme_date'
            && (array_key_exists('annee_debut', $validated) || array_key_exists('annee_fin', $validated))
        ) {
            Projet::where('id', $validated['projet_id'])->update([
                'annee_debut' => $validated['annee_debut'] ?? null,
                'annee_fin' => $validated['annee_fin'] ?? null,
            ]);
        }

        AuditLog::create([
            'contribution_id' => null,
            'action' => 'verification',
            'avant' => $avant ? json_encode($avant) : null,
            'apres' => json_encode([
                'projet_id' => $validated['projet_id'],
                'nom' => Projet::whereKey($validated['projet_id'])->value('nom'),
                'etat' => $etat,
                'statut_constate' => $statutConstate,
                'annee_debut' => $validated['annee_debut'] ?? null,
                'annee_fin' => $validated['annee_fin'] ?? null,
                'note' => $note,
                'lien' => $validated['lien'] ?? null,
            ]),
            'par_admin' => $user->name,
        ]);

        return response()->json(['ok' => true, 'etat' => $etat]);
    }
}
