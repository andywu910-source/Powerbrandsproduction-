<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

class BatchEquipment extends Model
{
    use Audited, NoDelete;
    protected $table = 'batch_equipment';
    protected $guarded = ['id'];
    protected $casts = ['ppe_ok' => 'boolean', 'condition_ok' => 'boolean', 'training_record_ids' => 'array', 'calibration_due_on' => 'date', 'maintenance_due_on' => 'date', 'voided_at' => 'datetime'];
    public function batch() { return $this->belongsTo(Batch::class); }
    public function person() { return $this->belongsTo(Person::class); }
    public function equipment() { return $this->belongsTo(Equipment::class); }
}
