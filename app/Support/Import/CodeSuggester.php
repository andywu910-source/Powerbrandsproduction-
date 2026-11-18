<?php

namespace App\Support\Import;

use App\Support\CodeRules;

/**
 * Suggests short codes that fit the Settings rules (default: brand 2 chars,
 * product 3 chars, A-Z and 2-9 without O/0/I/1). Always unique; always flagged
 * "needs review" by the importer so a person confirms them.
 */
class CodeSuggester
{
    /** Third character of a product code: the size / format. */
    public const SIZE_CHARS = [
        '0.3g' => 'T', '0.35g' => 'T', '0.5g' => 'H', '0.7g' => '7', '0.75g' => '7', '1g' => 'G', '1.25g' => 'Q', '1.5g' => 'X',
        '2g' => '2', '2.5g' => 'Y', '3g' => '3', '3.5g' => 'E', '5g' => '5', '7g' => 'W', '14g' => 'J', '28g' => 'Z',
        '2pk' => 'K', '3pk' => 'K', '5pk' => 'P', '10pk' => 'P',
    ];
    public const FAMILY_CHARS = ['preroll' => 'R', 'vape' => 'V', 'flower' => 'F', 'rosin' => 'S', 'green_mantis' => 'M'];

    private static function allowed(string $s): string
    {
        $cs = CodeRules::charset();
        // Look-alikes the default charset leaves out: O/0 -> Q, I/1 -> L.
        $s = strtr(strtoupper($s), ['O' => 'Q', '0' => 'Q', 'I' => 'L', '1' => 'L']);

        return implode('', array_filter(str_split($s), fn ($c) => str_contains($cs, $c)));
    }

    /** @param string[] $taken */
    public static function brand(string $brandName, array $taken): string
    {
        $len = CodeRules::brandLength();
        $words = preg_split('/\s+/', trim($brandName));
        $letters = self::allowed(preg_replace('/[^A-Za-z0-9]/', '', $brandName));
        $cands = [];
        $cands[] = self::allowed(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), $words)));
        $cands[] = substr($letters, 0, $len);
        for ($i = 1; $i < strlen($letters); $i++) {
            $cands[] = substr($letters, 0, 1).substr($letters, $i, $len - 1);
        }

        return self::firstFree($cands, $len, $taken, substr($letters, 0, 1));
    }

    /** @param string[] $taken codes already used inside this brand */
    public static function product(string $name, ?string $brand, ?string $family, array $taken): string
    {
        $len = CodeRules::productLength();
        $rest = $name;
        if ($brand && stripos(ProductNameNormalizer::clean($name), $brand) === 0) {
            $rest = substr(ProductNameNormalizer::clean($name), strlen($brand));
        }
        $size = ProductNameNormalizer::sizeOf($name);
        $sizeChar = $size !== null ? (self::SIZE_CHARS[$size] ?? null) : null;
        $sizeChar ??= self::FAMILY_CHARS[$family] ?? 'X';
        // Strain / flavor words: drop size and format words.
        $strain = preg_replace('/\b(\d+(\.\d+)?(g|ml|pk)|prerolls?|shorties|shortiez|loosies|vapes?|carts?|cartridge|aio|deuces?|dueces|disposable|flower|jars?|bags?|infused|rosin|tincture|tinture|of|the|and|x|-)\b/i', ' ', $rest);
        $words = array_values(array_filter(preg_split('/[\s,\/]+/', trim($strain))));
        $initials = self::allowed(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), $words)));
        $letters = self::allowed(implode('', $words));
        $stem = $len - 1;
        $cands = [];
        if (strlen($initials) >= $stem) $cands[] = substr($initials, 0, $stem).$sizeChar;
        if (strlen($letters) >= $stem) $cands[] = substr($letters, 0, $stem).$sizeChar;
        for ($i = 1; $i < strlen($letters); $i++) {
            $cands[] = substr($letters, 0, 1).substr($letters, $i, $stem - 1).$sizeChar;
        }

        return self::firstFree($cands, $len, $taken, (substr($letters, 0, 1) ?: 'X'));
    }

    private static function firstFree(array $cands, int $len, array $taken, string $lead): string
    {
        $takenSet = array_flip($taken);
        foreach ($cands as $c) {
            if (strlen($c) === $len && ! isset($takenSet[$c])) {
                return $c;
            }
        }
        // Fall back: keep the first letter(s), walk the charset for the rest.
        $cs = str_split(CodeRules::charset());
        $lead = substr($lead ?: $cs[0], 0, 1);
        $try = function (string $prefix, int $remaining) use (&$try, $cs, $takenSet) {
            if ($remaining === 0) {
                return isset($takenSet[$prefix]) ? null : $prefix;
            }
            foreach ($cs as $c) {
                if (($r = $try($prefix.$c, $remaining - 1)) !== null) {
                    return $r;
                }
            }

            return null;
        };
        $r = $try($lead, $len - 1);
        if ($r === null) {
            foreach ($cs as $c) {
                if (($r = $try($c, $len - 1)) !== null) break;
            }
        }

        return $r ?? throw new \RuntimeException('No free code left.');
    }
}
