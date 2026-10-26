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
        // Endpoint public : seules les contributions validées par un admin sont
        // publiées. Les contributions en attente ou refusées restent visibles
        // côté admin (GET /api/admin/contributions, panneau /admin).
        $rows = Contribution::query()
            ->select('projet_id', 'type', 'payload_json', 'commentaire', 'created_at', 'statut')
            ->where('statut', 'validee')
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
            // Acceptation explicite de la publication sous Licence Ouverte
            // 2.0 (case à cocher du formulaire). Optionnelle pour ne pas
            // casser les clients existants, tracée sur la contribution.
            'accepte_licence' => ['sometimes', 'boolean'],
        ]);

        if (! Projet::whereKey($validated['projet_id'])->exists()) {
            return response()->json(['error' => 'Projet introuvable'], 404);
        }

        $payload = ContributionService::payloadFromInput($validated);

        $contribution = Contribution::create([
            'projet_id' => $validated['projet_id'],
            'user_id' => $request->user()?->id,
            'type' => $validated['type'],
            'payload_json' => $payload,
            'commentaire' => $validated['commentaire'] ?? null,
            // Base légale de la rediffusion des corrections validées
            // (API publique, exports) : null = client ancien sans la case.
            'licence_acceptee' => array_key_exists('accepte_licence', $validated)
                ? (bool) $validated['accepte_licence']
                : null,
            'ip' => $request->ip(),
            'user_agent' => mb_substr($request->userAgent() ?? '', 0, 200),
        ]);

        // Admin et modérateur (experts de confiance) : la correction est
        // appliquée immédiatement, via le même pipeline que la validation
        // manuelle dans le panneau d'admin (application + journal d'audit,
        // réversible par « retirer »).
        $autoValidee = $request->user()->hasAnyRole(['admin', 'moderateur']);
        if ($autoValidee) {
            $service->applyContribution($contribution->id, $request->user()->name);
        }

        return response()->json(['ok' => true, 'id' => $contribution->id, 'auto_validee' => $autoValidee], 201);
    }
}
