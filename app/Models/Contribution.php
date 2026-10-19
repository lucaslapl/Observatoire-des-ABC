<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contribution extends Model
{
    protected $fillable = [
        'projet_id', 'type', 'payload_json', 'commentaire', 'ip',
        'user_agent', 'statut', 'traite_par', 'traite_le', 'note_admin',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'traite_le' => 'datetime',
        ];
    }

    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class, 'projet_id', 'id');
    }
}
