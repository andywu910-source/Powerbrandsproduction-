<?php

namespace App\Support;

/**
 * Splits a product into Brand › Product line › Flavor for the "Start a new batch" pickers.
 * Read-only and conservative: it only uses what the product NAME and FAMILY already say.
 *   line   = size + format words + type, e.g. "0.7g Shortiez Preroll", "1.25g Chopped Cheese Preroll", "2g AIO Vape"
 *   flavor = the words left over, e.g. "Lemon OG"
 * When a product cannot be split cleanly (process steps such as capping / labeling, several products
 * in one name, no family, no flavor words) it is NOT guessed: ok = false and the reason is given, and
 * the picker lists it under "Other (not sorted yet)" with its full name. An admin can set the line and
 * flavor by hand (products.product_line / products.flavor); those always win.
 */
class ProductLine
{
    public const OTHER = 'Other (not sorted yet)';

    public const TYPES = ['preroll' => 'Preroll', 'vape' => 'Vape', 'flower' => 'Flower', 'rosin' => 'Rosin', 'green_mantis' => null];

    /** format word pattern => canonical, in display order */
    private const FORMATS = [
        'chopped\s+cheese' => 'Chopped Cheese', 'bubble\s+hash' => 'Bubble Hash', 'kief|keif' => 'Kief', 'shortie[sz]' => 'Shortiez', 'loosie[sz]' => 'Loosiez', 'infused' => 'Infused',
        'ground\s+flowers?' => 'Ground Flower', 'aio' => 'AIO', 'deuces|dueces' => 'Deuces', 'cart(?:ridge|iadge|idage|idge)?s?' => 'Cart', 'disposables?' => 'Disposable',
        'live\s+rosin' => 'Live Rosin', 'live\s+resin' => 'Live Resin', 'tinctures?' => 'Tincture', 'lotions?' => 'Lotion', 'creams?' => 'Cream',
    ];

    /** words that mean a process step or a work order, not a finished product */
    private const PROCESS = 'capping|labell?ing|labels?|packagings?|shrinkband|ems|grinding|formulation|preparation|prep|r\s?&\s?d|rnd|part\s+\d+|coating|spray|infusion|infuson|samples?|jars|caps|run|qc|trim|grind|tote|mixture|bulk|box|casebox|masterbox|cardboard|case|correction';

    /** type words removed from the flavor (the type comes from the product family) */
    private const TYPE_WORDS = 'pre-?rolls?|pr|flowers?|vapes?|rosin|aio\s+vape';

    public static function compact(?string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $s));
    }

    public static function key(?string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));
    }

    /** Name without the brand words at the front ("Bodega Boyz Bodega Boyz Sample" -> "Sample"). */
    public static function stripBrand(string $name, ?string $brand): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $name));
        $b = self::compact($brand);
        if ($b === '') {
            return $s;
        }
        for ($guard = 0; $guard < 3; $guard++) {
            $w = explode(' ', $s);
            $hit = false;
            for ($k = 1; $k <= min(4, count($w) - 1); $k++) {
                if (self::compact(implode(' ', array_slice($w, 0, $k))) === $b) {
                    $s = implode(' ', array_slice($w, $k));
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                break;
            }
        }

        return trim($s);
    }

    /**
     * @return array{line: ?string, flavor: ?string, size: ?string, ok: bool, no_size: bool, why: ?string, rest: string}
     */
    public static function parse(string $name, ?string $brand, ?string $family): array
    {
        $rest = self::stripBrand($name, $brand);
        $out = ['line' => null, 'flavor' => null, 'size' => null, 'ok' => false, 'no_size' => false, 'why' => null, 'rest' => $rest];
        $s = ' '.$rest.' ';
        if (preg_match('/(?<![a-z])('.self::PROCESS.')(?![a-z])/i', $s, $m)) {
            $out['why'] = 'looks like a process step or work order ("'.trim($m[1]).'")';

            return $out;
        }
        if (preg_match('/,|\//', $rest)) {
            $out['why'] = 'the name lists several products';

            return $out;
        }
        if (! array_key_exists((string) $family, self::TYPES)) {
            $out['why'] = 'product family (preroll / vape / flower / rosin / Green Mantis) is not set';

            return $out;
        }
        if (preg_match('/^5\s*boro\b/i', $rest)) {
            $out['why'] = 'name starts with "5 Boro" (a sub-brand?)';

            return $out;
        }
        // pack: "0.7g 5pk 3.5g" = 5 x 0.7g (3.5g in all)
        if (preg_match('/(?<![\w.])(\d+(?:\.\d+)?|\.\d+)\s?g\s*(\d+)\s?pk(?:\s+(\d+(?:\.\d+)?)\s?g)?(?![a-z])/i', $s, $pk)) {
            $n = $pk[1][0] === '.' ? '0'.$pk[1] : $pk[1];
            if (! empty($pk[3]) && abs((float) $n * (int) $pk[2] - (float) $pk[3]) > 0.01) {
                $out['why'] = 'pack size does not add up ("'.trim($pk[0]).'")';

                return $out;
            }
            $out['size'] = $n.'g '.$pk[2].'pk';
            $s = str_replace($pk[0], ' ', $s);
        }
        // size
        preg_match_all('/(?<![\w.])(\d+(?:\.\d+)?|\.\d+)\s?(g|grams?|mg|ml)(?![a-z])|(?<![a-z])(oz)(?![a-z])/i', $s, $mm, PREG_SET_ORDER);
        if ($mm && $out['size'] !== null) {
            $out['why'] = 'more than one size in the name';

            return $out;
        }
        if (count($mm) > 1) {
            $out['why'] = 'more than one size in the name';

            return $out;
        }
        if ($mm && $out['size'] === null) {
            $m = $mm[0];
            if (! empty($m[3])) {
                $out['size'] = 'Oz';
            } else {
                $n = $m[1][0] === '.' ? '0'.$m[1] : $m[1];
                $u = strtolower($m[2]);
                $out['size'] = $n.(str_starts_with($u, 'gram') ? 'g' : $u);
            }
            $s = str_replace($m[0], ' ', $s);
        }
        // formats
        $formats = [];
        foreach (self::FORMATS as $re => $label) {
            if (in_array($label, ['Tincture', 'Lotion', 'Cream'], true) && $family !== 'green_mantis') {
                continue;   // "Garlic Cream" flower, "Noir Cream" vape: part of the strain name
            }
            if (preg_match('/(?<![a-z])(?:'.$re.')(?![a-z])/i', $s)) {
                $formats[] = $label;
                $s = preg_replace('/(?<![a-z])(?:'.$re.')(?![a-z])/i', ' ', $s);
            }
        }
        $s = preg_replace('/(?<![a-z])(?:'.self::TYPE_WORDS.')(?![a-z])/i', ' ', $s);
        $type = self::TYPES[$family];
        if ($family === 'green_mantis' && ! array_intersect($formats, ['Tincture', 'Lotion', 'Cream'])) {
            $out['why'] = 'Green Mantis product without tincture / lotion / cream in the name';

            return $out;
        }
        if (in_array('Ground Flower', $formats, true) && $type === 'Flower') {
            $type = null;
        }
        $flavor = trim(preg_replace('/\s+/u', ' ', $s), " \t-–:;.&+");
        if (preg_match('/(?<![\w.])(\d*\.\d+|\d+\s?pk|\d+\s?pack)(?![\w])/i', $flavor, $m)) {
            $out['why'] = 'size without a unit or unclear pack ("'.$m[1].'")';

            return $out;
        }
        if ($flavor === '' || mb_strlen(preg_replace('/[^a-z]/i', '', preg_replace('/\(.*?\)/', '', $flavor))) < 2) {
            $out['why'] = 'no flavor / strain words in the name';

            return $out;
        }
        $out['line'] = trim(implode(' ', array_filter([$out['size'], implode(' ', $formats), $type])));
        $out['flavor'] = $flavor;
        $out['no_size'] = $out['size'] === null;
        $out['ok'] = $out['line'] !== '';

        return $out;
    }
}
