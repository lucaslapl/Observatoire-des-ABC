<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Services\ContributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContributionController extends Controller
{
    public function index(): array
    {
        $rows = Contribution::query()
            ->leftJoin('projets', 'projets.id', '=', 'contributions.projet_id')
            ->leftJoin('verifications', 'verifications.projet_id', '=', 'contributions.projet_id')
            ->select([
                'contributions.*',
                'projets.nom as projet_nom',
                'projets.structure_porteuse',
                'verifications.etat as verif_etat',
                'verifications.note as verif_note',
                'verifications.lien as verif_lien',
            ])
            ->orderByRaw("CASE contributions.statut WHEN 'en_attente' THEN 0 WHEN 'validee' THEN 1 ELSE 2 END")
            ->orderByDesc('contributions.created_at')
            ->get();

        return ['contributions' => $rows];
    }

    public function valider(ContributionService $service, int $id): JsonResponse
    {
        $result = $service->applyContribution($id, Auth::user()->name);
        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], 400);
        }

        return response()->json(['ok' => true]);
    }

    public function refuser(Request $request, ContributionService $service, int $id): JsonResponse
    {
        $noteAdmin = $request->input('note_admin');
        $result = $service->rejectContribution($id, Auth::user()->name, $noteAdmin);
        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], 400);
        }

        return response()->json(['ok' => true]);
    }

    public function retirer(ContributionService $service, int $id): JsonResponse
    {
        $result = $service->revertContribution($id, Auth::user()->name);
        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], 400);
        }

        return response()->json(['ok' => true]);
    }
}
