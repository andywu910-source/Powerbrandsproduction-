<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class ScheduleWeek extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected $casts = ['week_start' => 'immutable_date'];

    public function roster() { return $this->hasMany(ScheduleRoster::class, 'week_id')->whereNull('removed_at')->orderBy('sort_order')->orderBy('id'); }
    public function cells() { return $this->hasMany(ScheduleCell::class, 'week_id'); }
    public function notes() { return $this->hasMany(ScheduleNote::class, 'week_id')->whereNull('removed_at')->orderBy('sort_order')->orderBy('id'); }
    public function copiedFrom() { return $this->belongsTo(self::class, 'copied_from_id'); }

    /** @return CarbonImmutable[] Monday..Friday */
    public function days(): array
    {
        return array_map(fn ($i) => $this->week_start->addDays($i), range(0, 4));
    }

    public function label(): string
    {
        return 'Week of '.$this->week_start->format('M j, Y');
    }
}
