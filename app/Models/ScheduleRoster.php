<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/** One person on one week's schedule, with that week's default day length. */
class ScheduleRoster extends Model
{
    use Audited;

    protected $table = 'schedule_roster';

    protected $guarded = ['id'];

    protected $casts = ['daily_hours' => 'float', 'removed_at' => 'datetime'];

    public function week() { return $this->belongsTo(ScheduleWeek::class, 'week_id'); }
    public function employee() { return $this->belongsTo(ScheduleEmployee::class, 'employee_id'); }
}
