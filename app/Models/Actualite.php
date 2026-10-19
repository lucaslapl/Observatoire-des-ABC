<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $date_publication
 * @property User|null $auteur
 */
class Actualite extends Model
{
    protected $fillable = ['titre', 'slug', 'contenu', 'auteur_id', 'statut', 'date_publication'];

    protected function casts(): array
    {
        return [
            'date_publication' => 'datetime',
        ];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
