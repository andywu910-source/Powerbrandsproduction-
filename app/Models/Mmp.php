<?php

namespace App\Models;

/** Master Manufacturing Protocol: the approved recipe (formulation) + method. Versioned. */
class Mmp extends RecordModel
{
    public const INFUSION_TYPES = ['distillate' => 'Distillate', 'terp' => 'Terp', 'hte' => 'HTE', 'diamond' => 'Diamond', 'kief' => 'Kief'];

    protected $casts = ['approved_at' => 'datetime', 'unit_fill_mass_g' => 'float', 'target_potency' => 'float', 'potency_min' => 'float', 'potency_max' => 'float',
        'yield_expected_pct' => 'float', 'yield_min_pct' => 'float', 'yield_max_pct' => 'float', 'infusion_pct' => 'float'];

    public function product() { return $this->belongsTo(Product::class); }
    public function pqp() { return $this->belongsTo(Pqp::class); }
    public function lines() { return $this->hasMany(MmpLine::class)->where('line_no', '>', 0)->orderBy('line_no'); }
    public function steps() { return $this->hasMany(MmpStep::class)->where('step_no', '>', 0)->orderBy('step_no'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }

    public function isEditable(): bool { return $this->status === 'draft'; }

    public function isCurrent(): bool
    {
        return $this->status === 'approved'
            && ! static::where('product_id', $this->product_id)->where('status', 'approved')->where('version', '>', $this->version)->exists();
    }

    /** MMP steps that apply to this product family's process template (by template, never by user choice). */
    public function applicableSteps()
    {
        $codes = ProcessTemplate::forFamily($this->product?->family)?->activeStages()->pluck('code')->all();
        $steps = $this->steps;
        if ($codes === null) {
            return $steps;
        }

        return $steps->filter(fn ($s) => $s->stage_code === null || in_array($s->stage_code, $codes, true))->values();
    }
}
