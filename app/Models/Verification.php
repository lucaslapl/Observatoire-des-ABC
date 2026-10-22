<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $verifie_le
 */
class Verification extends Model
{
    protected $primaryKey = 'projet_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['projet_id', 'etat', 'note', 'note_auto', 'lien', 'verifie_le', 'verifie_par'];

    /**
     * Sépare les motifs automatiques (« Doublon suspect (…) ») du travail
     * humain dans une note : les lignes machine vont dans note_auto, le
     * reste reste dans note. Utilisé par la migration de bascule.
     *
     * Les lignes machine peuvent être préfixées « [fusion de …] » (notes
     * concaténées par abc:merge-projects) ou suivre du texte humain sur la
     * même ligne (Source collée à la fin par ContributionService).
     *
     * @return array{0: string|null, 1: string|null} [note humaine, note_auto]
     */
    public static function splitAutoNotes(?string $note): array
    {
        if ($note === null || trim($note) === '') {
            return [null, null];
        }

        $humaines = [];
        $auto = [];
        foreach (preg_split('/\r\n|\r|\n/', $note) ?: [] as $ligne) {
            $ligne = trim($ligne);
            if ($ligne === '') {
                continue;
            }
            $pos = strpos($ligne, 'Doublon suspect (');
            if ($pos === false) {
                $humaines[] = $ligne;

                continue;
            }
            $prefixeFusion = preg_match('/^\[fusion de [^\]]+\]\s*/u', $ligne) === 1;
            if ($pos === 0 || $prefixeFusion) {
                $auto[] = $ligne;
            } else {
                $humaines[] = trim(substr($ligne, 0, $pos));
                $auto[] = trim(substr($ligne, $pos));
            }
        }

        return [
            $humaines === [] ? null : implode("\n", $humaines),
            $auto === [] ? null : implode("\n", $auto),
        ];
    }

    protected function casts(): array
    {
        return [
            'verifie_le' => 'datetime',
        ];
    }
}
