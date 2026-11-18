<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

class BatchStep extends Model
{
    use Audited, NoDelete;

    protected $guarded = ['id'];
    protected $casts = ['data' => 'array', 'opened_at' => 'datetime', 'completed_at' => 'datetime'];

    public function batch() { return $this->belongsTo(Batch::class); }
    public function signatures() { return $this->morphMany(Signature::class, 'signable')->orderBy('signed_at')->orderBy('id'); }
    public function checklist() { return $this->belongsTo(ChecklistInstance::class, 'checklist_instance_id'); }

    public function hasValidSignature(string $meaning): bool
    {
        return $this->signatures()->where('meaning', $meaning)->whereNull('invalidated_at')->exists();
    }

    public function label(): string { return "Step {$this->step_no}"; }
}
