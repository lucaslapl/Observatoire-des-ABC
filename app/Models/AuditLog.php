<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_log';

    protected $fillable = ['contribution_id', 'action', 'avant', 'apres', 'par_admin'];

    public function contribution()
    {
        return $this->belongsTo(Contribution::class);
    }
}
