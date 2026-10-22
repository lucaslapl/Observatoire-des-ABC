<?php

namespace Tests\Unit;

use App\Services\ContributionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Extraction des dates confirmées d'une note de vérification : la ligne
 * « Dates confirmées : 2026 » (ou « Dates confirmées : 2025–2027 ») peut être
 * préfixée « — » ou suivie d'un « — Source : … » collé en fin de ligne.
 */
class DatesFromNoteTest extends TestCase
{
    #[DataProvider('notes')]
    public function test_extrait_les_dates_confirmées(?string $note, ?array $attendu): void
    {
        $this->assertSame($attendu, ContributionService::datesFromNote($note));
    }

    public static function notes(): array
    {
        return [
            'note nulle' => [null, null],
            'note sans dates' => ['Vérifié sur le site de la ville', null],
            'début seul' => ['Dates confirmées : 2026', [2026, null]],
            'début avec source collée' => ['Dates confirmées : 2026 — Source : Ville de Vitré', [2026, null]],
            'plage début–fin' => ['Dates confirmées : 2025–2027 — Source : https://example.org', [2025, 2027]],
            'ligne préfixée' => ["Motif humain\n— Dates confirmées : 2024", [2024, null]],
        ];
    }
}
