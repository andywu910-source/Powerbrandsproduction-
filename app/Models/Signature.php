<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

/** Append-only. Only the one-time "invalidated by correction" mark can be set. */
class Signature extends Model
{
    use NoDelete;

    protected $guarded = ['id'];
    protected $casts = ['signed_at' => 'datetime', 'invalidated_at' => 'datetime'];

    public function signable() { return $this->morphTo(); }
    public function user() { return $this->belongsTo(User::class); }
    public function recordedBy() { return $this->belongsTo(User::class, 'recorded_by_user_id'); }

    public function isValid(): bool { return $this->invalidated_at === null; }
}
