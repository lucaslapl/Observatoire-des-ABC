<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ProjectExclusion;
use App\Models\Projet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjetController extends Controller
{
    /**
     * Supprime un projet jugé erroné (cascade : communes, snapshots,
     * enrichissements, vérifications, contributions) et l'exclut du prochain
     * collect, avec trace d'audit.
     */
    public function destroy(Request $request, Projet $projet): JsonResponse
    {
        $motif = $request->input('motif');

        ProjectExclusion::updateOrCreate(
            ['projet_id' => $projet->id],
            [
                'motif' => $motif ?: null,
                'par_admin' => $request->user()?->email,
            ],
        );

        AuditLog::create([
            'contribution_id' => null,
            'action' => 'projet_supprime',
            'avant' => json_encode(['projet_id' => $projet->id, 'nom' => $projet->nom]),
            'apres' => null,
            'par_admin' => $request->user()?->email,
        ]);

        $projet->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Liste des exclusions en cours (avec le nom du projet s'il existe encore,
     * sinon un projet « fantôme » supprimé mais toujours exclu).
     */
    public function index(): JsonResponse
    {
        $rows = ProjectExclusion::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ProjectExclusion $e) {
                return [
                    'projet_id' => $e->projet_id,
                    'motif' => $e->motif,
                    'par_admin' => $e->par_admin,
                    'cree_le' => $e->created_at?->toDateTimeString(),
                    'existe' => Projet::whereKey($e->projet_id)->exists(),
                ];
            });

        return response()->json(['exclusions' => $rows]);
    }

    /**
     * Lève une exclusion (laisse le collect ré-importer le projet).
     */
    public function unexclude(Request $request, string $projet): JsonResponse
    {
        ProjectExclusion::where('projet_id', $projet)->delete();

        AuditLog::create([
            'contribution_id' => null,
            'action' => 'exclusion_retiree',
            'avant' => json_encode(['projet_id' => $projet]),
            'apres' => null,
            'par_admin' => $request->user()?->email,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Export CSV du journal d'audit (suppressions / levées d'exclusion) pour contrôle.
     */
    public function export(Request $request): StreamedResponse
    {
        $logs = AuditLog::query()
            ->whereIn('action', ['projet_supprime', 'exclusion_retiree'])
            ->orderByDesc('created_at')
            ->get();
        $exclusions = ProjectExclusion::query()->orderByDesc('created_at')->get();
        $now = now()->format('Y-m-d H:i:s');

        $stream = function () use ($logs, $exclusions, $now) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Journal ABC - export du '.$now], ';');
            fputcsv($out, ['']);
            fputcsv($out, ['ACTIONS']);
            fputcsv($out, ['date', 'action', 'projet_id', 'nom', 'par_admin'], ';');
            foreach ($logs as $log) {
                $avant = json_decode($log->avant ?? '{}', true) ?: [];
                fputcsv($out, [
                    $log->created_at?->toDateTimeString(),
                    $log->action,
                    $avant['projet_id'] ?? $log->contribution_id ?? '',
                    $avant['nom'] ?? '',
                    $log->par_admin ?? '',
                ], ';');
            }
            fputcsv($out, ['']);
            fputcsv($out, ['EXCLUSIONS EN COURS']);
            fputcsv($out, ['date', 'projet_id', 'motif', 'par_admin'], ';');
            foreach ($exclusions as $e) {
                fputcsv($out, [
                    $e->created_at?->toDateTimeString(),
                    $e->projet_id,
                    $e->motif ?? '',
                    $e->par_admin ?? '',
                ], ';');
            }
            fclose($out);
        };

        $filename = 'audit-abc-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload($stream, $filename, ['Content-Type' => 'text/csv; charset=utf-8; header=present']);
    }
}
