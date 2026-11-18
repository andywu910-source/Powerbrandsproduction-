<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

/** A correction: old value, new value, reason, who, initials, when. Append-only. */
class Revision extends Model
{
    use NoDelete;

    protected $guarded = ['id'];
    protected $casts = ['corrected_at' => 'datetime'];

    public function correctedBy() { return $this->belongsTo(User::class, 'corrected_by'); }
}
