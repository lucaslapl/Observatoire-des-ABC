<?php

namespace Tests\Unit;

use App\Models\Verification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Séparation des motifs automatiques (« Doublon suspect (…) », écrits par le
 * collecteur Fonds vert et le script de triage) et du travail humain dans
 * verifications.note : la migration de bascule s'appuie sur ce split.
 */
class VerificationSplitAutoNotesTest extends TestCase
{
    public static function casDeSplit(): array
    {
        return [
            'note nulle' => [null, null, null],
            'note humaine seule' => [
                'Dates confirmées : 2024 — Source : site de la commune',
                'Dates confirmées : 2024 — Source : site de la commune',
                null,
            ],
            'note automatique seule' => [
                'Doublon suspect (Paire N°43 du triage 2026-10-21) : vérifier',
                null,
                'Doublon suspect (Paire N°43 du triage 2026-10-21) : vérifier',
            ],
            'melange humain puis automatique' => [
                "Dates confirmées : 2026\nDoublon suspect (Fonds vert P113 2024 ligne abc) : « Atlas » semble financer ce projet.",
                'Dates confirmées : 2026',
                'Doublon suspect (Fonds vert P113 2024 ligne abc) : « Atlas » semble financer ce projet.',
            ],
            'melange avec lignes vides' => [
                "Doublon suspect (Paire N°1 du triage 2026-10-21) : motif\n\nSource : Ville de Vitré\n",
                'Source : Ville de Vitré',
                'Doublon suspect (Paire N°1 du triage 2026-10-21) : motif',
            ],
            'motif colle a une ligne humaine (source concatenee)' => [
                'Dates confirmées : 2026 — Source : Ville de Vitré Doublon suspect (Fonds vert P113 2024 ligne abc) : motif.',
                'Dates confirmées : 2026 — Source : Ville de Vitré',
                'Doublon suspect (Fonds vert P113 2024 ligne abc) : motif.',
            ],
            'motif prefixe par une fusion' => [
                '[fusion de abc-d-ambigu-b-2024] Doublon suspect (Paire N°23 du triage 2026-10-21) : motif',
                null,
                '[fusion de abc-d-ambigu-b-2024] Doublon suspect (Paire N°23 du triage 2026-10-21) : motif',
            ],
        ];
    }

    #[DataProvider('casDeSplit')]
    public function test_split(?string $note, ?string $noteAttendue, ?string $noteAutoAttendue): void
    {
        [$noteHumaine, $noteAuto] = Verification::splitAutoNotes($note);

        $this->assertSame($noteAttendue, $noteHumaine);
        $this->assertSame($noteAutoAttendue, $noteAuto);
    }
}
