<?php

namespace App\Models;

use Illuminate\Support\Carbon;

class Equipment extends RecordModel
{
    protected $table = 'equipment';
    protected $casts = ['is_scale' => 'boolean', 'active' => 'boolean', 'last_calibrated_on' => 'date', 'calibration_due_on' => 'date', 'maintenance_due_on' => 'date',
        'wm_reported_on' => 'date', 'wm_sealed_on' => 'date', 'last_inspected_on' => 'date', 'inspection_due_on' => 'date',
        'capacity' => 'float', 'division' => 'float'];

    protected static function booted(): void
    {
        // Scale saved (added, edited, inspection recorded): maybe switch the scale block on.
        static::saved(function (Equipment $e) {
            if ($e->is_scale) {
                \App\Services\ScaleBlock::maybeEnable();
            }
        });
    }

    public function room() { return $this->belongsTo(Room::class); }
    public function inspections() { return $this->hasMany(ScaleInspection::class, 'equipment_id')->orderByDesc('inspected_on')->orderByDesc('id'); }

    public function scopeScales($q) { return $q->where('is_scale', true); }

    /** Months between Weights & Measures inspections (NY: at least yearly). */
    public static function inspectionMonths(): int
    {
        return max(1, min(12, (int) Setting::get('scale_inspection_interval_months', 12)));
    }

    public static function dueSoonDays(): int
    {
        return max(1, (int) Setting::get('scale_due_soon_days', 30));
    }

    /** Next inspection due = last inspection + interval (default 12 months). */
    public static function defaultDue(?\DateTimeInterface $last): ?string
    {
        return $last ? Carbon::instance($last)->addMonthsNoOverflow(self::inspectionMonths())->toDateString() : null;
    }

    /**
     * Scale register status: ok / due_soon / overdue / missing (no inspection date on file)
     * / inactive. Not a scale: null.
     */
    public function inspectionStatus(): ?string
    {
        if (! $this->is_scale) {
            return null;
        }
        if (! $this->active) {
            return 'inactive';
        }
        if (! $this->inspection_due_on) {
            return 'missing';
        }
        $today = now()->timezone(config('pbp.site_timezone'))->toDateString();
        $due = $this->inspection_due_on->toDateString();
        if ($due < $today) {
            return 'overdue';
        }
        if ($due <= Carbon::parse($today)->addDays(self::dueSoonDays())->toDateString()) {
            return 'due_soon';
        }

        return 'ok';
    }

    public static function statusLabel(?string $s): string
    {
        return match ($s) {
            'ok' => 'In date', 'due_soon' => 'Due soon', 'overdue' => 'Overdue', 'missing' => 'No inspection on file', 'inactive' => 'Not in use', default => '',
        };
    }

    public static function statusPill(?string $s): string
    {
        return match ($s) { 'ok' => 'ok', 'due_soon' => 'warn', 'overdue', 'missing' => 'bad', default => '' };
    }

    /** Plain-English problems that stop this equipment being used today. */
    public function problems(): array
    {
        $today = now()->toDateString();
        $p = [];
        if (! $this->active) $p[] = "{$this->equip_id} is not active.";
        if ($this->calibration_due_on === null) $p[] = "{$this->equip_id} has no calibration due date on file.";
        elseif ($this->calibration_due_on->toDateString() < $today) $p[] = "{$this->equip_id} calibration was due {$this->calibration_due_on->format('M j, Y')}.";
        if ($this->maintenance_due_on && $this->maintenance_due_on->toDateString() < $today) $p[] = "{$this->equip_id} maintenance was due {$this->maintenance_due_on->format('M j, Y')}.";
        if ($this->is_scale && blank($this->wm_seal_ref)) $p[] = "{$this->equip_id} is a scale with no Weights & Measures approval on file.";
        if ($this->is_scale && $this->active && Setting::get('gate_refuse_uninspected_scales', true)) {
            $st = $this->inspectionStatus();
            if ($st === 'overdue') $p[] = "{$this->equip_id} Weights & Measures inspection was due {$this->inspection_due_on->format('M j, Y')} (scale register).";
            if ($st === 'missing') $p[] = "{$this->equip_id} has no Weights & Measures inspection date in the scale register.";
        }

        return $p;
    }

    public function label(): string { return $this->equip_id.' – '.$this->name; }
}
