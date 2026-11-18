<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

class BatchMmpStep extends Model
{
    use Audited, NoDelete;
    protected $guarded = ['id'];
    protected $casts = ['actual_value' => 'float', 'within_limit' => 'boolean', 'performed_at' => 'datetime', 'voided_at' => 'datetime', 'staff_person_ids' => 'array'];
    public array $correctable = ['actual_value', 'observation'];

    public function batch() { return $this->belongsTo(Batch::class); }
    public function mmpStep() { return $this->belongsTo(MmpStep::class); }
    public function deviation() { return $this->belongsTo(Deviation::class); }
    public function performer() { return $this->belongsTo(Person::class, 'performed_by_person_id'); }
    public function signatures() { return $this->morphMany(Signature::class, 'signable')->orderBy('signed_at')->orderBy('id'); }
    public function revisions() { return $this->morphMany(Revision::class, 'record', 'record_type', 'record_id')->orderBy('id'); }

    public function validSignature(string $meaning): ?Signature
    {
        return $this->signatures()->where('meaning', $meaning)->whereNull('invalidated_at')->first();
    }

    /** A corrected step must be verified again (if it needs a verifier and the batch is still at step 6). */
    public function afterCorrection(): void
    {
        if ($this->status === 'verified' && $this->mmpStep?->requires_verifier && $this->batch?->step(6)?->status === 'open') {
            $this->update(['status' => 'recorded']);
        }
        if ($this->mmpStep?->hasLimits() && $this->actual_value !== null) {
            $ms = $this->mmpStep;
            $this->update(['within_limit' => ($ms->limit_min === null || $this->actual_value >= $ms->limit_min) && ($ms->limit_max === null || $this->actual_value <= $ms->limit_max)]);
        }
    }

    public function label(): string { return 'MMP step '.$this->step_no; }
}
