<?php

namespace App\Services;

use App\Exceptions\GateFailed;
use App\Models\MaterialLot;
use App\Models\Mmp;

/**
 * Scales an approved recipe (MMP) to a batch:
 *  - by_mass: amount × (target units / recipe units) × (1 + overage)
 *  - by_count: same, rounded up (hardware, packaging, labels)
 *  - potency_adjusted (distillate): target cannabinoid mass ÷ the lot's COA potency
 *      target cannabinoid mass = units × fill per unit × target % (or units × mg per unit)
 *  - potency_balancer (carrier): fill per unit × units − the other liquid/mass lines
 * Amounts are in base units (g / ml / ea) to 0.01.
 */
class BatchCalculator
{
    /**
     * @param  array<int,int>  $lotForLine  mmp_line_id => material_lot_id (needed for potency-adjusted lines)
     * @return array{inputs: array, results: array}
     */
    public function calculate(Mmp $mmp, int $targetUnits, ?float $targetPotency, array $lotForLine = []): array
    {
        if ($targetUnits <= 0) {
            throw GateFailed::because('Enter how many units this batch will make.');
        }
        if ($mmp->basis_units <= 0) {
            throw GateFailed::because('The recipe has no basis unit count.');
        }
        $hasActive = $mmp->lines->contains(fn ($l) => $l->scale_mode === 'potency_adjusted');
        if ($hasActive) {
            if ($targetPotency === null) {
                throw GateFailed::because('Enter the target potency.');
            }
            if (($mmp->potency_min !== null && $targetPotency < $mmp->potency_min) || ($mmp->potency_max !== null && $targetPotency > $mmp->potency_max)) {
                throw GateFailed::because("Target potency {$targetPotency} is outside the recipe's allowed range ({$mmp->potency_min}–{$mmp->potency_max}).");
            }
            if (! $mmp->unit_fill_mass_g && $mmp->potency_basis === 'pct') {
                throw GateFailed::because('The recipe needs a fill amount per unit to work out the distillate.');
            }
        }

        $factor = $targetUnits / $mmp->basis_units;
        $results = [];
        $inputs = ['target_units' => $targetUnits, 'target_potency' => $targetPotency, 'potency_basis' => $mmp->potency_basis,
            'unit_fill_mass_g' => $mmp->unit_fill_mass_g, 'factor' => round($factor, 6), 'lots' => []];
        $massUsed = 0.0;
        $balancer = null;

        foreach ($mmp->lines as $line) {
            $over = 1 + ((float) $line->overage_pct / 100);
            $row = ['mmp_line_id' => $line->id, 'line_no' => $line->line_no, 'ingredient_id' => $line->ingredient_id,
                'ingredient' => $line->ingredient?->name, 'line_type' => $line->line_type, 'scale_mode' => $line->scale_mode,
                'unit' => $line->unit, 'tolerance_pct' => $line->effectiveTolerancePct()];

            switch ($line->scale_mode) {
                case 'by_count':
                    $row['planned_qty'] = (float) ceil(round($line->qty_per_basis * $factor * $over, 6));
                    $row['math'] = "{$line->qty_per_basis} per {$mmp->basis_units} units × {$targetUnits} units".($line->overage_pct ? " + {$line->overage_pct}% overage" : '').', rounded up';
                    break;
                case 'potency_adjusted':
                    $lotId = $lotForLine[$line->id] ?? null;
                    $lot = $lotId ? MaterialLot::find($lotId) : null;
                    if (! $lot || $lot->ingredient_id !== $line->ingredient_id) {
                        throw GateFailed::because("Pick the {$line->ingredient?->name} lot first so its COA potency can be used.");
                    }
                    if ($lot->coa_result !== 'pass' || ! $lot->potency_pct) {
                        throw GateFailed::because("Lot {$lot->public_id} has no passing COA with a potency, so it can't be used for the calculation.");
                    }
                    $cannabinoidG = $mmp->potency_basis === 'mg'
                        ? $targetUnits * $targetPotency / 1000
                        : $targetUnits * $mmp->unit_fill_mass_g * $targetPotency / 100;
                    $row['planned_qty'] = round($cannabinoidG / ($lot->potency_pct / 100) * $over, 2);
                    $row['lot_id'] = $lot->id;
                    $row['lot_potency_pct'] = $lot->potency_pct;
                    $row['math'] = 'needs '.round($cannabinoidG, 2).' g cannabinoids ÷ '.$lot->potency_pct.'% lot potency ('.$lot->public_id.')';
                    $inputs['lots'][$line->id] = ['lot_id' => $lot->id, 'public_id' => $lot->public_id, 'potency_pct' => $lot->potency_pct];
                    $massUsed += $row['planned_qty'];
                    break;
                case 'potency_balancer':
                    $balancer = count($results);
                    $row['planned_qty'] = 0;
                    break;
                default:
                    $row['planned_qty'] = round($line->qty_per_basis * $factor * $over, 2);
                    $row['math'] = "{$line->qty_per_basis} {$line->unit} per {$mmp->basis_units} units × {$targetUnits} units".($line->overage_pct ? " + {$line->overage_pct}% overage" : '');
                    if (in_array($line->unit, ['g', 'ml'], true) && ! in_array($line->line_type, ['hardware', 'packaging', 'label'], true)) {
                        $massUsed += $row['planned_qty'];
                    }
            }
            $results[] = $row;
        }

        if ($balancer !== null) {
            if (! $mmp->unit_fill_mass_g) {
                throw GateFailed::because('The recipe needs a fill amount per unit for the carrier line.');
            }
            $fill = $targetUnits * $mmp->unit_fill_mass_g;
            $qty = round($fill - $massUsed, 2);
            if ($qty < 0) {
                throw GateFailed::because('The active ingredient and terpenes already exceed the fill amount. Check the lot potency or the target.');
            }
            $results[$balancer]['planned_qty'] = $qty;
            $results[$balancer]['math'] = 'fill '.round($fill, 2).' g − other ingredients '.round($massUsed, 2).' g';
        }

        return ['inputs' => $inputs, 'results' => $results];
    }
}
