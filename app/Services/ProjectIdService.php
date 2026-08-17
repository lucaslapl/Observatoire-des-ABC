<?php

namespace App\Services;

/**
 * Identifiants stables des projets.
 *
 * L'algorithme doit reproduire EXACTEMENT l'ancien `slug()` TypeScript :
 * NFD → suppression des diacritiques → minuscules → remplacement des
 * caractères non [a-z0-9] par "-" → trim des "-" aux extrémités.
 */
class ProjectIdService
{
    public function slug(string $key): string
    {
        $normalized = \Normalizer::normalize($key, \Normalizer::FORM_D);
        if ($normalized === false) {
            $normalized = $key;
        }
        // Suppression des diacritiques (bloc U+0300–U+036F).
        $ascii = preg_replace('/[\x{0300}-\x{036f}]/u', '', $normalized) ?? $key;
        $lower = mb_strtolower($ascii, 'UTF-8');
        $dashed = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? $lower;

        return trim($dashed, '-');
    }

    /**
     * Clé stable d'identification d'un projet (le registre ABC n'expose pas d'UUID).
     */
    public function projetId(string $nom, ?string $structure = null, int|string|null $annee = null): string
    {
        $structure ??= '';
        $annee ??= '';

        return $this->slug($nom.'|'.$structure.'|'.$annee);
    }
}
