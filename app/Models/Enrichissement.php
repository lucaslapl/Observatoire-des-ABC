<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Enrichissement extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['projet_id', 'description', 'documents_json'];

    protected function casts(): array
    {
        return [
            'documents_json' => 'array',
        ];
    }
}
