<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Reads a mix the way the Production Log writes it and normalizes it to fractions:
 *   "90/5/5" -> [0.90, 0.05, 0.05]     "50/50" -> [0.5, 0.5]
 *   "125g/125g" -> [0.5, 0.5]          "16%" or "0.16" or "16" -> [0.16]
 *   "5%/5%" -> [0.05, 0.05] (percent parts; any remainder belongs to the base material)
 */
class MixSpec
{
    /** @return float[] fractions (0..1) */
    public static function parse(string $spec): array
    {
        $s = strtolower(trim(str_replace([' ', ','], ['', '.'], $spec)));
        if ($s === '') {
            throw new InvalidArgumentException('Enter a percent (e.g. 16%) or a ratio (e.g. 90/5/5).');
        }
        $parts = preg_split('#[/:]#', $s);
        $isPercent = str_contains($s, '%');
        $vals = [];
        foreach ($parts as $p) {
            $p = preg_replace('/(g|ml|%)$/', '', $p);
            if (! is_numeric($p) || (float) $p < 0) {
                throw new InvalidArgumentException("Could not read '{$spec}'. Use a percent like 16% or a ratio like 90/5/5.");
            }
            $vals[] = (float) $p;
        }
        if (count($vals) === 1) {
            $v = $vals[0];
            $f = $isPercent || $v > 1 ? $v / 100 : $v;
            if ($f > 1) {
                throw new InvalidArgumentException('A percent cannot be more than 100%.');
            }

            return [round($f, 6)];
        }
        if ($isPercent) {
            $sum = array_sum($vals);
            if ($sum > 100.0001) {
                throw new InvalidArgumentException('The percents add up to more than 100%.');
            }

            return array_map(fn ($v) => round($v / 100, 6), $vals);
        }
        $sum = array_sum($vals);
        if ($sum <= 0) {
            throw new InvalidArgumentException('A ratio needs at least one part above zero.');
        }

        return array_map(fn ($v) => round($v / $sum, 6), $vals);
    }

    /** Percent (0-100) from "16%", "0.16", "16", "22.5". */
    public static function percent(string $spec): float
    {
        return round(self::parse($spec)[0] * 100, 4);
    }
}
