<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Financement Fonds vert rattaché à un projet ABC existant.
 *
 * @property int $id
 * @property string $project_id
 * @property string $source ex. fondsvert-p113-2025
 * @property string $ligne_id identifiant de la ligne source (n° de dossier)
 * @property string|null $beneficiaire
 * @property int|null $annee_financement
 * @property string|null $montant
 * @property array $ligne_brute ligne CSV source complète
 */
class Financement extends Model
{
    protected $fillable = [
        'project_id', 'source', 'ligne_id', 'beneficiaire',
        'annee_financement', 'montant', 'ligne_brute',
    ];

    protected function casts(): array
    {
        return [
            'ligne_brute' => 'array',
            'montant' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Projet, $this> */
    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class, 'project_id', 'id');
    }
}
