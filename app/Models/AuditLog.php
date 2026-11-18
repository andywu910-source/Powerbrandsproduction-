<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use NoDelete;

    protected $table = 'audit_log';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
}
