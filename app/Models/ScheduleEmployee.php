<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/** A person on the Weekly Schedule (not a login; may be linked to one). */
class ScheduleEmployee extends Model
{
    use Audited;

    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'];

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean', 'recurring_off' => 'array', 'default_hours' => 'float', 'hourly_rate' => 'float'];

    public function user() { return $this->belongsTo(User::class); }

    public function offOn(int $isoWeekday): bool
    {
        return in_array($isoWeekday, array_map('intval', $this->recurring_off ?? []), true);
    }

    public function recurringOffText(): string
    {
        $d = array_map(fn ($n) => self::WEEKDAYS[(int) $n] ?? '?', $this->recurring_off ?? []);

        return $d ? 'Off every '.implode(', ', $d) : '';
    }
}
