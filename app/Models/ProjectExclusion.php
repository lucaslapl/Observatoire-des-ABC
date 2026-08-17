<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectExclusion extends Model
{
    protected $primaryKey = 'projet_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['projet_id', 'motif', 'par_admin'];
}
