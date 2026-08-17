<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        Verification::updateOrCreate(
            ['projet_id' => $validated['projet_id']],
            [
                'etat' => $validated['etat'],
                'note' => $validated['note'] ?? null,
                'lien' => $validated['lien'] ?? null,
                'verifie_le' => now(),
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

        return response()->json(['ok' => true]);
    }
}
