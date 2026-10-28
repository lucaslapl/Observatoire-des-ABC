<?php

namespace App\Services;

use App\Models\Commune;
use Illuminate\Support\Str;

class RegionService
{
    public function label(?string $code): ?string
    {
        if (! $code) {
            return null;
        }
        $regions = config('regions');
        $value = trim($code);
        if (isset($regions[$value])) {
            return $regions[$value];
        }
        if (ctype_digit($value)) {
            return null;
        }

        // Libellé de la source parfois déaccentué ou mal casé
        // (« Auvergne-Rhone-Alpes ») : rattrage sur les libellés
        // canoniques de la configuration, insensible aux accents et à
        // la casse.
        $cle = $this->fold($value);
        foreach ($regions as $libelle) {
            if ($this->fold($libelle) === $cle) {
                return $libelle;
            }
        }

        // Libellé hors référentiel (ex. Nouvelle-Calédonie) : conservé
        // tel quel, pourvu qu'il contienne une lettre.
        return preg_match('/\p{L}/u', $value) ? $value : null;
    }

    /**
     * Clé de comparaison insensible aux accents, à la casse et aux
     * variations tiret/espace (« Ile de France » ≡ « Île-de-France »).
     */
    private function fold(string $libelle): string
    {
        $ascii = Str::ascii(mb_strtolower(trim($libelle)));

        return trim((string) preg_replace('/[-\s]+/u', '-', $ascii), '-');
    }

    public function slug(string $label): string
    {
        return Str::slug($label);
    }

    /**
     * Tous les libellés de régions connus, indexés par slug URL.
     * (config + libellés réellement présents dans la base, ex. Nouvelle-Calédonie)
     *
     * @return array<string, string>
     */
    public function slugsIndex(): array
    {
        $map = [];
        foreach (config('regions') as $label) {
            $map[$this->slug($label)] = $label;
        }
        $values = Commune::query()
            ->whereNotNull('region')
            ->distinct()
            ->pluck('region');
        foreach ($values as $value) {
            $label = $this->label($value);
            if ($label) {
                $map[$this->slug($label)] = $label;
            }
        }

        return $map;
    }

    public function resolveBySlug(string $slug): ?string
    {
        return $this->slugsIndex()[$slug] ?? null;
    }

    /**
     * Normalise une valeur (code ou libellé) en libellé.
     */
    public function normalizeToLabel(?string $value): ?string
    {
        return $this->label($value);
    }
}
