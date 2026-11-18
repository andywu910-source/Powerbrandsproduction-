<?php

namespace App\Support;

use App\Models\Product;
use Carbon\CarbonInterface;

/**
 * NY OCM Cannabis Sampling Quality System Standard (rev. 1/14/26).
 *  - Table 1: representative sample units per test, by packaged batch size (single-serve / multi-serving).
 *  - Final Cannabis Product Batch, item B: a batch may contain no more than 150,000 packaged units.
 *  - Item C: retain enough samples for complete testing at least two times, for at least
 *    30 days after the expiration date.
 * Computed on the fly from the batch's planned units (nothing stored).
 */
final class OcmSampling
{
    public const MAX_UNITS = 150000;
    public const TOO_BIG = 'OCM: batches over 150,000 packaged units are not allowed (item B)';
    public const INFO = 'OCM Cannabis Sampling QS Standard (rev. 1/14/26), Table 1; Final Cannabis Product Batch item C (retain 2× complete testing, keep 30 days past expiration). Labs may request extra samples.';
    public const MULTI_NOT_ALLOWED = 'Multi-serving is only for edibles and capsule-type products (OCM Table 1, footnote A). It never applies to packaged whole or ground flower, pre-rolls, or non-edible concentrates such as tinctures, hash oil and vapes.';
    public const RETAIN_COPIES = 2;
    public const RETAIN_DAYS_AFTER_EXPIRY = 30;

    public const SERVING_TYPES = ['single' => 'Single-serve (one serving per package)', 'multi' => 'Multi-serving (edibles / capsules only)'];

    /** Table 1: [max batch size, single-serve units, multi-serving units]. */
    public const TABLE = [
        [500, 5, 2],
        [1200, 8, 3],
        [3200, 13, 3],
        [10000, 20, 4],
        [35000, 32, 5],
        [150000, 50, 6],
    ];

    /** Sample units for one complete test; null when there are no units or the batch is too big. */
    public static function perTest(?int $units, string $serving = 'single'): ?int
    {
        if (! $units || $units < 1 || $units > self::MAX_UNITS) {
            return null;
        }
        foreach (self::TABLE as [$max, $single, $multi]) {
            if ($units <= $max) {
                return $serving === 'multi' ? $multi : $single;
            }
        }

        return null;
    }

    /** Retention samples: enough for complete testing at least two times (item C). */
    public static function retention(?int $units, string $serving = 'single'): ?int
    {
        $n = self::perTest($units, $serving);

        return $n === null ? null : $n * self::RETAIN_COPIES;
    }

    public static function retainUntil(?CarbonInterface $expiration): ?CarbonInterface
    {
        return $expiration?->copy()->addDays(self::RETAIN_DAYS_AFTER_EXPIRY);
    }

    /** Multi-serving is allowed only for edibles and capsule-type products (footnote A). */
    public static function multiAllowed(?string $family, ?string $productType, ?string $name = null): bool
    {
        if (in_array($family, ['preroll', 'vape', 'flower', 'rosin'], true)) {
            return false;
        }
        $t = mb_strtolower(trim(($productType ?? '').' '.($name ?? '')));
        if (preg_match('/tinctur|hash|oil|vape|cart|pre-?roll|flower|ground|concentrate|rosin|resin|wax|shatter|topical/u', $t)) {
            return false;
        }

        return (bool) preg_match('/edible|gumm|chocolate|candy|candies|chew|mint|lozenge|baked|cookie|brownie|beverage|drink|capsule|softgel|soft gel|tablet|pill/u', $t);
    }

    public static function servingFor(?Product $product): string
    {
        return ($product?->serving_type === 'multi' && self::multiAllowed($product->family, $product->product_type, $product->name)) ? 'multi' : 'single';
    }

    /** Table 1 for the browser (live update on the batch form). */
    public static function tableForJs(): array
    {
        return ['max' => self::MAX_UNITS, 'rows' => self::TABLE, 'copies' => self::RETAIN_COPIES, 'too_big' => self::TOO_BIG];
    }
}
