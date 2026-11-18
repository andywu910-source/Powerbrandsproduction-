<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

class MmpLine extends Model
{
    use Audited, NoDelete;

    public const SCALE_MODES = [
        'by_mass' => 'Scale with batch size',
        'by_count' => 'Count per unit (rounded up)',
        'potency_adjusted' => 'Adjust to the lot\'s COA potency (active)',
        'potency_balancer' => 'Fills the rest of the unit (carrier)',
    ];

    protected $guarded = ['id'];
    protected $casts = ['qty_per_basis' => 'float', 'tolerance_pct' => 'float', 'overage_pct' => 'float'];

    public function mmp() { return $this->belongsTo(Mmp::class); }
    public function ingredient() { return $this->belongsTo(Ingredient::class); }

    public function effectiveTolerancePct(): float
    {
        if ($this->tolerance_pct !== null) {
            return (float) $this->tolerance_pct;
        }
        if ($this->scale_mode === 'by_count' || in_array($this->line_type, ['hardware', 'packaging', 'label'], true)) {
            return (float) Setting::get('tolerance_count_pct', 0);
        }

        return (float) match ($this->line_type) {
            'cannabis_active' => Setting::get('tolerance_cannabis_active_pct', 2),
            'terpene' => Setting::get('tolerance_terpene_pct', 5),
            'carrier' => Setting::get('tolerance_carrier_pct', 5),
            default => Setting::get('tolerance_other_pct', 5),
        };
    }
}
