<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/** A checklist item in a week's notes (Going to Lab, IN LAB, ...). */
class ScheduleNote extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected $casts = ['done' => 'boolean', 'removed_at' => 'datetime'];

    public const GOING_TO_LAB = 'Going to lab';
    public const PENDING = 'Pending';

    public function week() { return $this->belongsTo(ScheduleWeek::class, 'week_id'); }

    public function batch() { return $this->belongsTo(Batch::class); }

    /** "[batch #] - note" */
    public function line(): string
    {
        $bn = $this->batch?->batch_number;

        return $bn ? ($this->text !== '' ? "$bn - {$this->text}" : $bn) : $this->text;
    }
}
