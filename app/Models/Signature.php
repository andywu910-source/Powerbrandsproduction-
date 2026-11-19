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

    public function voidedBy() { return $this->belongsTo(User::class, 'voided_by_user_id'); }

    public function isValid(): bool { return $this->invalidated_at === null; }

    /** Voided by an admin (as opposed to marked no longer valid because the record was corrected). */
    public function isVoided(): bool { return $this->voided_by_user_id !== null; }

    /** The name as signed (kept even if the account is renamed later); older sign-offs fall back to the account name. */
    public function signerName(): string { return $this->signer_name ?: (string) $this->user?->name; }
}
