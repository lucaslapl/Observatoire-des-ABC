<?php

namespace App\Services;

/**
 * Règles de statut et de catégorie (port exact de src/status.ts et des
 * règles de recomputeStatuses dans src/collect.ts).
 */
class StatusService
{
    public const RAW_TO_AGREGE = [
        'En cours de réalisation' => 'en_cours',
        'Fini' => 'termine',
        'En phase de lancement' => 'a_venir',
        'Non commencé' => 'a_venir',
        'Inconnu' => 'inconnu',
    ];

    public const CATEGORIE_LABEL = [
        'va_debuter' => 'Va débuter',
        'a_venir' => 'Va débuter',
        'en_cours' => 'En cours',
        'termine' => 'Terminé',
        'inconnu' => 'Statut inconnu',
    ];

    public const CATEGORIE_ORDER = [
        'en_cours' => 0,
        'va_debuter' => 1,
        'termine' => 2,
        'inconnu' => 3,
    ];

    public function avancementToStatut(?string $raw): string
    {
        if (! $raw) {
            return 'inconnu';
        }

        return self::RAW_TO_AGREGE[$raw] ?? 'inconnu';
    }

    public function statutLabel(string $s): string
    {
        return self::CATEGORIE_LABEL[$s] ?? $s;
    }

    /**
     * Combine le statut actuel avec l'historique des snapshots Wayback :
     * si jamais documenté "Fini" sur un snapshot antérieur, on considère le
     * projet terminé.
     */
    public function resolveCategorie(string $statutCourant, array $snapshotAvancements, bool $viaFondsVert2025): string
    {
        if (in_array('Fini', $snapshotAvancements, true)) {
            return 'termine';
        }
        if ($statutCourant === 'a_venir' && $viaFondsVert2025) {
            return 'va_debuter';
        }
        if ($statutCourant === 'a_venir') {
            return 'va_debuter';
        }
        if ($statutCourant === 'en_cours') {
            return 'en_cours';
        }
        if ($statutCourant === 'termine') {
            return 'termine';
        }

        return 'inconnu';
    }

    /**
     * Un ABC dure ~3 ans. Si le statut est encore "en cours" et que le projet
     * a commencé il y a plus de DUREE_ABC_ANS, il est probablement terminé.
     */
    public function estPotentiellementTermine(?string $statut, ?int $anneeDebut, ?int $anneeCourante = null): bool
    {
        if ($statut !== 'en_cours') {
            return false;
        }
        if (! $anneeDebut) {
            return false;
        }
        $year = $anneeCourante ?? (int) date('Y');

        return $year - $anneeDebut > config('abc.duree_abc_ans');
    }

    /**
     * Verdict de vérification manuelle → statut affiché sur la carte.
     * Les verdicts non concluants (introuvable, douteux, à vérifier) ne changent rien.
     */
    public function statutDepuisVerification(?string $etat): ?string
    {
        return match ($etat) {
            'confirme_termine' => 'termine',
            'confirme_en_cours' => 'en_cours',
            'toujours_a_venir' => 'a_venir',
            default => null,
        };
    }

    /**
     * Statut suggéré (catégorie carte) → verdict officiel de la table verifications.
     */
    public function verificationEtatPourStatut(string $s): ?string
    {
        return match ($s) {
            'termine' => 'confirme_termine',
            'en_cours' => 'confirme_en_cours',
            'va_debuter' => 'toujours_a_venir',
            default => null,
        };
    }
}
