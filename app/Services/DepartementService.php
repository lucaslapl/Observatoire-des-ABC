<?php

namespace App\Services;

use App\Models\Commune;

/**
 * Référentiel des départements (config/departements.php) : libellés
 * canoniques pour l'affichage, indépendants des libellés portés par les
 * sources (data.gouv, Fonds vert), qui varient selon le millésime
 * (« Rhône » / « Rhone »).
 */
class DepartementService
{
    /**
     * Libellé canonique d'un code département (métropole ou outre-mer),
     * null si le code est absent du référentiel.
     */
    public function label(?string $code): ?string
    {
        $norm = mb_strtolower(trim((string) $code));

        return config('departements')[$norm] ?? null;
    }

    /**
     * Code département dérivé du préfixe du code INSEE d'une commune :
     * 2 caractères (métropole, Corse « 2a »/« 2b »), 3 pour l'outre-mer
     * (97x/98x uniquement — les communes de Seine-Saint-Denis « 93xxx »
     * ou du Val-d'Oise « 95xxx » restent sur 2 caractères). Rattrape les
     * lignes communes dont la colonne `departement` est vide (ex.
     * millésimes récents de data.gouv).
     */
    public function codeDepuisInsee(?string $codeInsee): ?string
    {
        $code = mb_strtolower(trim((string) $codeInsee));
        if ($code === '') {
            return null;
        }

        $length = preg_match('/^9[78]/', $code) === 1 ? 3 : 2;

        return mb_substr($code, 0, $length) ?: null;
    }

    /**
     * Tous les codes départements présents dans les lignes communes
     * (hors anomalies) : colonne `departement` quand elle est renseignée,
     * sinon préfixe du code INSEE — les lignes incomplètes ne disparaissent
     * plus des filtres ni du sitemap.
     *
     * @return array<int, string>
     */
    public function codesPresents(): array
    {
        return Commune::query()
            ->where('anomalie', false)
            ->distinct()
            ->pluck('code_geographique')
            ->map(fn ($c) => $this->codeDepuisInsee($c))
            ->filter()
            ->merge(
                Commune::query()
                    ->where('anomalie', false)
                    ->whereNotNull('departement')
                    ->distinct()
                    ->pluck('departement')
                    ->map(fn ($c) => mb_strtolower(trim((string) $c)))
                    ->filter(fn ($c) => $c !== '')
            )
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
