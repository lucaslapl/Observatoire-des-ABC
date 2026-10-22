<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alias d'un projet fusionné : le doublon n'est plus un projet mais son id
 * et son slug sont conservés (redirection 301 + anti-réimport au collect).
 *
 * @property int $id
 * @property string $project_id id du projet canonique
 * @property string $alias_project_id id de l'ancien projet (doublon)
 * @property string $alias_slug slug historique du doublon
 * @property string|null $source
 * @property string|null $motif
 * @property string|null $created_by
 */
class ProjectAlias extends Model
{
    /** La table ne porte que created_at (jet le jour de la fusion). */
    const UPDATED_AT = null;

    protected $fillable = [
        'project_id', 'alias_project_id', 'alias_slug', 'source', 'motif', 'created_by',
    ];

    /** @return BelongsTo<Projet, $this> */
    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class, 'project_id', 'id');
    }
}
