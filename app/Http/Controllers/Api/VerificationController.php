<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Projet;
use App\Models\Verification;
use App\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function show(VerificationService $service): array
    {
        return $service->list();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'projet_id' => ['required', 'string'],
            'etat' => ['required', 'string'],
            'note' => ['nullable', 'string'],
            'lien' => ['nullable', 'string'],
            'annee_debut' => ['nullable', 'integer'],
            'annee_fin' => ['nullable', 'integer'],
        ]);

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
                'etat' => $validated['etat'],
                'note' => $validated['note'] ?? null,
                'lien' => $validated['lien'] ?? null,
                'verifie_le' => now(),
                'verifie_par' => $user->name,
            ],
        );

        if (
            $validated['etat'] === 'confirme_date'
            && array_key_exists('annee_debut', $validated)
            && array_key_exists('annee_fin', $validated)
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
                'etat' => $validated['etat'],
                'note' => $validated['note'] ?? null,
                'lien' => $validated['lien'] ?? null,
            ]),
            'par_admin' => $user->name,
        ]);

        return response()->json(['ok' => true]);
    }
}
