<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/** One person, one day. status null = nothing scheduled (counts 0 hours). */
class ScheduleCell extends Model
{
    use Audited;

    public const STATUSES = ['working' => 'Working', 'off' => 'Off', 'partial' => 'Partial day'];

    protected $guarded = ['id'];

    protected $casts = ['day' => 'immutable_date', 'hours' => 'float', 'from_recurring' => 'boolean'];

    public function week() { return $this->belongsTo(ScheduleWeek::class, 'week_id'); }
    public function employee() { return $this->belongsTo(ScheduleEmployee::class, 'employee_id'); }
    public function jobs() { return $this->hasMany(ScheduleJob::class, 'cell_id')->whereNull('removed_at')->orderBy('sort_order')->orderBy('id'); }

    /** Hours this cell counts: Working = the week's default day, Partial = its own hours, Off / empty = 0. */
    public static function hoursFor(?self $cell, float $dailyHours): float
    {
        return match ($cell?->status) {
            'working' => $dailyHours,
            'partial' => (float) $cell->hours,
            default => 0.0,
        };
    }
}
