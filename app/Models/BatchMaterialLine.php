<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

class BatchMaterialLine extends Model
{
    use Audited, NoDelete;
    protected $guarded = ['id'];
    protected $casts = ['planned_qty' => 'float', 'actual_qty' => 'float', 'variance_pct' => 'float', 'tolerance_pct' => 'float', 'within_tolerance' => 'boolean', 'voided_at' => 'datetime'];

    public function batch() { return $this->belongsTo(Batch::class); }
    public function mmpLine() { return $this->belongsTo(MmpLine::class); }
    public function ingredient() { return $this->belongsTo(Ingredient::class); }
    public function lot() { return $this->belongsTo(MaterialLot::class, 'material_lot_id'); }
    public function scale() { return $this->belongsTo(Equipment::class, 'scale_equipment_id'); }
    public function deviation() { return $this->belongsTo(Deviation::class); }
    public function signatures() { return $this->morphMany(Signature::class, 'signable')->orderBy('signed_at')->orderBy('id'); }

    public function validSignature(string $meaning): ?Signature
    {
        return $this->signatures()->where('meaning', $meaning)->whereNull('invalidated_at')->first();
    }

    public function label(): string { return 'Weigh-out line #'.$this->id; }
}
