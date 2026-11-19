<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NoDelete;
use App\Models\Concerns\Voidable;
use Illuminate\Database\Eloquent\Model;

/** One row in a Compliance hub log. Never deleted; void it with a reason. Who entered it and when is always kept. */
class LogEntry extends Model
{
    use Audited, NoDelete, Voidable;

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array', 'occurred_on' => 'date', 'voided_at' => 'datetime', 'verified_at' => 'datetime'];

    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const RETURNED = 'returned';

    /** [pill class, label] for the verification badge. */
    public function verificationBadge(): array
    {
        return match (true) {
            $this->verification_status === self::RETURNED => ['bad', 'Returned'],
            $this->voided_at !== null => ['bad', 'Void'],
            $this->verification_status === self::VERIFIED => ['ok', 'Verified'],
            default => ['warn', 'Pending verification'],
        };
    }

    public function isPendingVerification(): bool
    {
        return $this->voided_at === null && ($this->verification_status ?? self::PENDING) === self::PENDING;
    }

    /** Pending, not void. */
    public function scopePendingVerification($q)
    {
        return $q->whereNull('log_entries.voided_at')->where('log_entries.verification_status', self::PENDING);
    }

    public function definition() { return $this->belongsTo(LogDefinition::class, 'log_definition_id'); }
    public function enteredBy() { return $this->belongsTo(User::class, 'entered_by_user_id'); }
    public function verifiedBy() { return $this->belongsTo(User::class, 'verified_by_user_id'); }
    public function links() { return $this->hasMany(LogEntryLink::class); }
    public function signatures() { return $this->morphMany(Signature::class, 'signable'); }
    public function attachments() { return $this->hasMany(Attachment::class, 'record_id')->where('record_type', 'log_entry'); }

    public function value(string $key)
    {
        return $this->data[$key] ?? null;
    }
}
