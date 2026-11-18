<?php

namespace App\Support\Import;

/**
 * Cleans up product names from the Production Log's "Product List" tab:
 * case, spacing, obvious misspellings (Dueces -> Deuces, Tinture -> Tincture,
 * Muha Med -> Muha Meds ...) and size spelling (1 g / 1gram -> 1g, .5g -> 0.5g).
 * Every original spelling is kept as an alias. Nothing here is a guess about what
 * the product IS; anything unclear is flagged "needs review".
 */
class ProductNameNormalizer
{
    /** Brands seen in the Production Log (prefix of the product name). */
    public const BRANDS = [
        'Bodega Boyz', 'Static', 'Muha Meds', 'Celestial', 'Noizey', 'NAR', 'Banzzy', 'Synergy', 'Sticky Smallz', 'Wizard Tree',
        'High Mart', 'Kitty Kreme', 'Cure Injoy', 'Hai', 'Apollo', 'Doja', 'Azone', 'Dada', 'Savvi', 'Otherwise',
        'Higher Vibrations', 'Mechanic Farm', 'Green Mantis', 'Super Dope 800', 'Frosty Fill',
    ];

    /** Obvious spelling variants (word level, lower case). */
    public const FIXES = [
        'dueces' => 'deuces', 'duece' => 'deuce', 'tinture' => 'tincture', 'tintures' => 'tinctures',
        'packagaing' => 'packaging', 'packageing' => 'packaging', 'preperation' => 'preparation',
        'prerolls' => 'prerolls', 'pre-roll' => 'preroll', 'pre-rolls' => 'prerolls', 'pre roll' => 'preroll',
        'cartridges' => 'cartridge', 'carts' => 'cart', 'loosiez' => 'loosies', 'shortiez' => 'shorties', 'infushed' => 'infused', 'keif' => 'kief',
    ];

    public const BRAND_VARIANTS = [
        'muha med' => 'Muha Meds', 'higher vibration' => 'Higher Vibrations', 'cure enjoy' => 'Cure Injoy', 'bodega boys' => 'Bodega Boyz',
    ];

    public static function clean(string $name): string
    {
        $s = preg_replace('/\s+/u', ' ', trim(str_replace(["\u{00A0}", "\t"], ' ', $name)));
        $s = preg_replace('/(?<![\d.])\.(\d)/', '0.$1', $s);                 // .5g -> 0.5g
        $s = preg_replace('/(\d(?:\.\d+)?)\s*(?:grams?|gr|g)\b/i', '$1g', $s); // 1 gram -> 1g
        $s = preg_replace('/(\d)\s*(pk|pack)\b/i', '$1pk', $s);
        $s = preg_replace('/\s+,/', ',', $s);

        return trim($s);
    }

    /** Lookup key used to merge duplicates. */
    public static function key(string $name): string
    {
        $s = mb_strtolower(self::clean($name));
        foreach (self::BRAND_VARIANTS as $from => $to) {
            if (str_starts_with($s, $from.' ') && ! str_starts_with($s, mb_strtolower($to))) {
                $s = mb_strtolower($to).substr($s, strlen($from));
            }
        }
        $s = preg_replace('/[^a-z0-9.%&\/ ]+/u', ' ', $s);
        $words = array_map(fn ($w) => self::FIXES[$w] ?? $w, preg_split('/\s+/', trim($s)));

        return trim(preg_replace('/\s+/', ' ', implode(' ', $words)));
    }

    /** Pick the display name for a group of spellings: the most used, then the best capitalised. */
    public static function canonical(array $spellings): string
    {
        $counts = array_count_values(array_map([self::class, 'clean'], $spellings));
        uksort($counts, function ($a, $b) use ($counts) {
            if ($counts[$a] !== $counts[$b]) return $counts[$b] <=> $counts[$a];
            $caps = fn ($s) => preg_match_all('/\b[A-Z]/', $s);

            return $caps($b) <=> $caps($a) ?: strcmp($a, $b);
        });
        $name = array_key_first($counts);
        foreach (self::BRAND_VARIANTS as $from => $to) {
            if (stripos($name, $from.' ') === 0 && stripos($name, $to) !== 0) {
                $name = $to.substr($name, strlen($from));
            }
        }
        // Fix only plain misspellings in the display name (brand-style spellings like
        // "Shortiez" or "Loosiez" stay as written; they still merge through the key).
        foreach (['dueces' => 'Deuces', 'tinture' => 'Tincture', 'packagaing' => 'Packaging', 'preperation' => 'Preparation', 'infushed' => 'Infused'] as $from => $to) {
            $name = preg_replace('/\b'.preg_quote($from, '/').'\b/i', $to, $name);
        }

        return $name;
    }

    public static function brandOf(string $name): ?string
    {
        $k = self::key($name);
        foreach (self::BRANDS as $b) {
            $bk = mb_strtolower($b);
            if ($k === $bk || str_starts_with($k, $bk.' ')) {
                return $b;
            }
        }

        return null;
    }

    /** @var array<string,string> lower-case name => family, from the sheets each name was logged on */
    public static array $hints = [];

    public static function familyOf(string $name, array $spellings = []): ?string
    {
        foreach (array_merge([$name], $spellings) as $s) {
            if (isset(self::$hints[mb_strtolower(trim($s))])) {
                return self::$hints[mb_strtolower(trim($s))];
            }
        }
        $k = self::key($name);

        return match (true) {
            (bool) preg_match('/green mantis|tincture|lotion|cream|transdermal|topical|balm/', $k) => 'green_mantis',
            (bool) preg_match('/\brosin\b/', $k) => 'rosin',
            (bool) preg_match('/vape|\bcart|aio|deuce|disposable|\bpod\b|510|all in one/', $k) => 'vape',
            (bool) preg_match('/preroll|shortie|loosie|\d+pk\b|joint|blunt|\bcone|infused|infusion|grinding|filling/', $k) => 'preroll',
            (bool) preg_match('/flower|\bjar|\bbag|\boz\b|bulk|smalls|popcorn|\b(3\.5|7|14|28)g\b/', $k) => 'flower',
            default => null,
        };
    }

    /** Names that read like a work task (prep, formulation, inventory...) rather than a product. */
    public static function looksLikeTask(string $name): bool
    {
        return (bool) preg_match('/formulation|preparation|\bprep|label correction|labeling|inventory|clean ?up|cleaning|organizing|samples?\b|r&d|coating|machines|packaging\b|prepping/', self::key($name));
    }

    /** Size/format part of a name, e.g. "1g", "0.5g", "3.5g", "5pk". */
    public static function sizeOf(string $name): ?string
    {
        $k = self::key($name);
        if (preg_match('/(\d+)pk\b/', $k, $m)) {
            return $m[1].'pk';
        }
        if (preg_match('/(\d+(?:\.\d+)?)g\b/', $k, $m)) {
            return ((string) (float) $m[1]).'g';
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*ml\b/', $k, $m)) {
            return $m[1].'ml';
        }

        return null;
    }
}
