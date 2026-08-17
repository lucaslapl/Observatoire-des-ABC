<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\Projet;
use App\Services\ContributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContributionController extends Controller
{
    public function index(Request $request): array
    {
        $rows = Contribution::query()
            ->select('projet_id', 'type', 'payload_json', 'commentaire', 'created_at', 'statut')
            ->orderByDesc('created_at')
            ->get();

        return ['contributions' => $rows];
    }

    public function store(Request $request, ContributionService $service): JsonResponse
    {
        $validated = $request->validate([
            'projet_id' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(ContributionService::CONTRIBUTION_TYPES)],
            'statut_suggere' => ['sometimes', Rule::in(['termine', 'en_cours', 'va_debuter'])],
            'annee_debut_suggeree' => ['sometimes', 'integer', 'min:1990', 'max:2040'],
            'annee_fin_suggeree' => ['sometimes', 'integer', 'min:1990', 'max:2040'],
            'note' => ['sometimes', 'string', 'max:2000'],
            'lien' => ['sometimes', 'string', 'max:500'],
            'texte' => ['sometimes', 'string', 'max:2000'],
            'source' => ['sometimes', 'string', 'max:500'],
            'commentaire' => ['sometimes', 'string', 'max:1000'],
        ]);

        if (! Projet::whereKey($validated['projet_id'])->exists()) {
            return response()->json(['error' => 'Projet introuvable'], 404);
        }

        $payload = ContributionService::payloadFromInput($validated);

        $contribution = Contribution::create([
            'projet_id' => $validated['projet_id'],
            'type' => $validated['type'],
            'payload_json' => $payload,
            'commentaire' => $validated['commentaire'] ?? null,
            'ip' => $request->ip(),
            'user_agent' => mb_substr($request->userAgent() ?? '', 0, 200),
        ]);

        return response()->json(['ok' => true, 'id' => $contribution->id], 201);
    }
}
