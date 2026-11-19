<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/**
 * One person, one day. Blank = working (Nov 19): a weekday with no cell, or a cell with no status, counts as Working
 * at the week's day length. People only mark Off or Partial. Saved rows are never rewritten for this; it is read logic.
 */
class ScheduleCell extends Model
{
    use Audited;

    public const STATUSES = ['working' => 'Working', 'off' => 'Off', 'partial' => 'Partial day'];

    protected $guarded = ['id'];

    protected $casts = ['day' => 'immutable_date', 'hours' => 'float', 'from_recurring' => 'boolean'];

    public function week() { return $this->belongsTo(ScheduleWeek::class, 'week_id'); }
    public function employee() { return $this->belongsTo(ScheduleEmployee::class, 'employee_id'); }
    public function jobs() { return $this->hasMany(ScheduleJob::class, 'cell_id')->whereNull('removed_at')->orderBy('sort_order')->orderBy('id'); }

    /** working | partial | off. Blank (no cell, or no status) = working. */
    public static function effective(?self $cell): string
    {
        $s = $cell?->status;

        return in_array($s, ['off', 'partial'], true) ? $s : 'working';
    }

    /** True when the day shows Working only because nothing was marked (blank = working). */
    public static function isDefault(?self $cell): bool
    {
        return ! in_array($cell?->status, ['working', 'off', 'partial'], true);
    }

    /** Hours this cell counts: Working (incl. blank) = the week's day length, Partial = its own hours, Off = 0. */
    public static function hoursFor(?self $cell, float $dailyHours): float
    {
        return match (self::effective($cell)) {
            'partial' => (float) $cell->hours,
            'off' => 0.0,
            default => $dailyHours,
        };
    }
}
