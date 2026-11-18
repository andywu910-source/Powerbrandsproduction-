<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

class MmpStep extends Model
{
    use Audited, NoDelete;
    protected $guarded = ['id'];
    protected $casts = ['limit_min' => 'float', 'limit_max' => 'float', 'requires_verifier' => 'boolean'];
    public function mmp() { return $this->belongsTo(Mmp::class); }
    public function hasLimits(): bool { return $this->limit_min !== null || $this->limit_max !== null; }
}
