<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Standard units. Everything is stored in a base unit: grams (g), millilitres (ml)
 * or count (ea). Entry can be in mg / kg / lb / oz / L and is converted on save.
 */
class Units
{
    public const BASE = ['g' => 'grams', 'ml' => 'millilitres', 'ea' => 'count'];

    /** entry unit => [base unit, factor] */
    public const ENTRY = [
        'g' => ['g', 1.0], 'mg' => ['g', 0.001], 'kg' => ['g', 1000.0], 'lb' => ['g', 453.59237], 'oz' => ['g', 28.349523125],
        'ml' => ['ml', 1.0], 'l' => ['ml', 1000.0],
        'ea' => ['ea', 1.0],
    ];

    public static function normalizeUnit(string $unit): string
    {
        $u = strtolower(trim($unit));

        return match ($u) {
            'gram', 'grams', 'gr' => 'g',
            'kilogram', 'kilograms', 'kgs' => 'kg',
            'milligram', 'milligrams' => 'mg',
            'liter', 'litre', 'liters', 'litres' => 'l',
            'milliliter', 'millilitre', 'milliliters', 'millilitres', 'mls' => 'ml',
            'each', 'unit', 'units', 'pcs', 'pc', 'count', 'ct' => 'ea',
            'lbs', 'pound', 'pounds' => 'lb',
            default => $u,
        };
    }

    /** @return array{0: float, 1: string} [qty in base unit, base unit] */
    public static function toBase(float $qty, string $unit): array
    {
        $u = self::normalizeUnit($unit);
        if (! isset(self::ENTRY[$u])) {
            throw new InvalidArgumentException("Unknown unit '{$unit}'.");
        }
        [$base, $f] = self::ENTRY[$u];

        return [round($qty * $f, 4), $base];
    }

    public static function baseOf(string $unit): string
    {
        return self::ENTRY[self::normalizeUnit($unit)][0] ?? 'g';
    }

    /** Friendly display: 1250 g -> "1.25 kg", 2500 ml -> "2.5 L", 12 ea -> "12 units". */
    public static function display(?float $qty, string $baseUnit): string
    {
        if ($qty === null) {
            return '';
        }
        // Trim trailing zeros of the decimals only (5,000 stays 5,000; 2.50 -> 2.5; 3.00 -> 3).
        $fmt = function ($n, $d = 2) {
            $s = number_format($n, $d, '.', ',');

            return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
        };

        return match ($baseUnit) {
            'g' => abs($qty) >= 1000 ? $fmt($qty / 1000, 3).' kg' : $fmt($qty).' g',
            'ml' => abs($qty) >= 1000 ? $fmt($qty / 1000, 3).' L' : $fmt($qty).' ml',
            'ea' => $fmt($qty, 0).' '.(abs($qty) == 1 ? 'unit' : 'units'),
            default => $fmt($qty).' '.$baseUnit,
        };
    }

    public static function entryOptions(): array
    {
        return ['g' => 'g', 'kg' => 'kg', 'mg' => 'mg', 'lb' => 'lb', 'oz' => 'oz', 'ml' => 'ml', 'l' => 'L', 'ea' => 'units (count)'];
    }
}
