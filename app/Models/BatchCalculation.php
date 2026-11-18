<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

/** Frozen snapshot of the batch calculator. Recalculating adds a new row. */
class BatchCalculation extends Model
{
    use Audited, NoDelete;
    protected $guarded = ['id'];
    protected $casts = ['inputs' => 'array', 'results' => 'array', 'superseded' => 'boolean'];
    public function signatures() { return $this->morphMany(Signature::class, 'signable'); }
    public function calculatedBy() { return $this->belongsTo(User::class, 'calculated_by'); }
    public function label(): string { return 'Batch calculation #'.$this->id; }
}
