<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Décision humaine sur un groupe d'homonymes : les projets portant ce nom
 * (normalisé en nom_slug) ont été tranchés « non-doublon » depuis /verify.
 * Tant que la décision tient, le collecteur ne recrée pas les notes
 * automatiques « Doublon suspect » pour ce nom.
 *
 * @property int $id
 * @property string $nom_slug nom normalisé du groupe d'homonymes
 * @property string $nom
 * @property string $decision
 * @property string|null $motif
 * @property string|null $par_admin
 */
class DoublonDecision extends Model
{
    protected $fillable = [
        'nom_slug', 'nom', 'decision', 'motif', 'par_admin',
    ];
}
