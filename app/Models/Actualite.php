<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Actualite extends Model
{
    protected $fillable = ['titre', 'slug', 'contenu', 'auteur_id', 'statut', 'date_publication'];

    protected function casts(): array
    {
        return [
            'date_publication' => 'datetime',
        ];
    }

    public function auteur()
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
