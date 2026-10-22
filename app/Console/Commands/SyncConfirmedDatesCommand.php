<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Projet;
use App\Models\Verification;
use App\Services\ContributionService;
use Illuminate\Console\Command;

/**
 * Matérialise les dates confirmées par un humain : la ligne « Dates
 * confirmées : 2026 » ou « Dates confirmées : 2025–2027 » d'une note de
 * vérification est écrite dans projets.annee_debut / annee_fin.
 *
 * Les vérifications héritées de l'ère Node portaient la date confirmée
 * uniquement dans la note : les pages de liste (département, commune,
 * fiche, carte) affichaient alors la date source, voire aucune date.
 *
 * Idempotent : un projet dont les colonnes correspondent déjà à la note
 * n'est pas retouché. Journalisé dans audit_log (action sync_dates).
 */
class SyncConfirmedDatesCommand extends Command
{
    protected $signature = 'abc:sync-confirmed-dates {--dry-run : lister les corrections sans les écrire}';

    protected $description = 'Écrit les dates confirmées des notes de vérification dans projets (idempotent, journalisé)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $relus = 0;
        $modifies = 0;

        Verification::query()
            ->where('note', 'LIKE', '%Dates confirm%')
            ->orderBy('projet_id')
            ->get()
            ->each(function (Verification $v) use (&$relus, &$modifies, $dryRun) {
                $dates = ContributionService::datesFromNote($v->note);
                if ($dates === null) {
                    return;
                }
                [$debut, $fin] = $dates;

                $projet = Projet::find($v->projet_id);
                if (! $projet) {
                    return;
                }
                $relus++;

                if ((int) $projet->annee_debut === $debut && (int) $projet->annee_fin === (int) $fin) {
                    return; // déjà conforme
                }

                $avant = ['annee_debut' => $projet->annee_debut, 'annee_fin' => $projet->annee_fin];
                if (! $dryRun) {
                    $projet->update(['annee_debut' => $debut, 'annee_fin' => $fin]);
                    AuditLog::create([
                        'action' => 'sync_dates',
                        'avant' => json_encode(['projet_id' => $v->projet_id] + $avant),
                        'apres' => json_encode(['projet_id' => $v->projet_id, 'annee_debut' => $debut, 'annee_fin' => $fin]),
                        'par_admin' => 'abc:sync-confirmed-dates',
                    ]);
                }
                $modifies++;

                $ancienne = $avant['annee_debut'] ?? '—';
                $ancienneFin = $avant['annee_fin'] ? '–'.$avant['annee_fin'] : '';
                $this->line("{$v->projet_id} : {$ancienne}{$ancienneFin} → {$debut}".($fin ? "–{$fin}" : ''));
            });

        $this->info($dryRun
            ? "--dry-run : {$modifies} projet(s) seraient corrigés sur {$relus} avec dates confirmées, aucun changement écrit."
            : "{$modifies} projet(s) corrigé(s) sur {$relus} avec dates confirmées (journalisé dans audit_log, action sync_dates).");

        return self::SUCCESS;
    }
}
