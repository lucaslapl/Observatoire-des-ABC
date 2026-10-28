<?php

namespace App\Services;

/**
 * Nettoyage d'affichage des noms de projets et structures porteuses.
 *
 * Les noms viennent bruts des sources CSV (registre OFB, Fonds vert) :
 * guillemets résiduels du CSV, espaces multiples, et parfois la
 * structure porteuse concaténée au nom par la source. Ce service ne
 * touche qu'au rendu public (fiches, liste /abc) — ni les identifiants,
 * ni les slugs, ni les exports/API ne sont affectés.
 */
class NomService
{
    /**
     * Nom de projet prêt à afficher : guillemets résiduels retirés,
     * espaces consolidés, et structure porteuse concaténée au nom par la
     * source retirée (elle est affichée séparément). Le retrait du
     * porteur n'attaque que la concaténation réelle (séparée par des
     * espaces multiples dans le brut) : un porteur qui n'est que le nom
     * de la commune (« ABC d'Angers » / « Angers ») reste dans le nom.
     */
    public function projet(?string $nom, ?string $structurePorteuse = null): string
    {
        $porteur = $this->nettoyer($structurePorteuse);
        if ($porteur !== '') {
            // Espaces multiples entre le nom et le porteur : signature de
            // la concaténation source. Chaque espace du porteur devient
            // \s+ pour tolérer les blancs irréguliers du brut.
            $motif = '/\s{2,}'.str_replace(' ', '\s+', preg_quote($porteur, '/')).'\s*$/iu';
            $nom = preg_replace($motif, '', (string) $nom) ?? $nom;
        }

        return $this->nettoyer($nom);
    }

    /**
     * Structure porteuse prête à afficher (trim + espaces consolidés),
     * null si vide.
     */
    public function structurePorteuse(?string $structurePorteuse): ?string
    {
        $nettoyee = $this->nettoyer($structurePorteuse);

        return $nettoyee === '' ? null : $nettoyee;
    }

    /**
     * Guillemets doubles résiduels du CSV retirés, espaces multiples
     * consolidés, bords trimés.
     */
    private function nettoyer(?string $valeur): string
    {
        if ($valeur === null) {
            return '';
        }

        $valeur = str_replace('"', '', $valeur);

        return trim((string) preg_replace('/\s+/u', ' ', $valeur));
    }
}
