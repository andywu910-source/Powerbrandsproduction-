<?php

namespace App\Services;

use App\Exceptions\GateFailed;
use App\Models\Batch;
use App\Models\Product;
use App\Models\Setting;
use App\Support\CodeRules;
use Illuminate\Support\Carbon;

/**
 * Batch numbers (Andy's rule): the main identifier for every batch.
 * Default pattern {BRAND}{PRODUCT}{YYMMDD}{SUFFIX} -> ST5BR261117, then ST5BR261117B, C, D...
 * The counter is per "everything except the counter" (e.g. per product per day) and
 * is taken under a row lock, so two people starting batches at once get different numbers.
 */
class BatchNumberGenerator
{
    public const TOKENS = ['BRAND', 'BRAND2', 'PRODUCT', 'PRODUCT3', 'TYPE', 'PRODUCT_CODE', 'YYYY', 'YY', 'MM', 'DD', 'YYMMDD', 'SEQ2', 'SEQ3', 'SUFFIX', 'SUFFIX_NUM'];
    public const COUNTERS = ['SEQ2', 'SEQ3', 'SUFFIX', 'SUFFIX_NUM'];

    /** Problems with a pattern, or [] if fine. */
    public static function validatePattern(string $pattern): array
    {
        $errors = [];
        if (trim($pattern) === '') {
            return ['The pattern cannot be empty.'];
        }
        preg_match_all('/\{([A-Z0-9_]+)\}/', $pattern, $m);
        foreach ($m[1] as $tok) {
            if (! in_array($tok, self::TOKENS, true)) {
                $errors[] = "Unknown token {{$tok}}.";
            }
        }
        $counters = array_intersect($m[1], self::COUNTERS);
        if (count($counters) !== 1) {
            $errors[] = 'Use exactly one counter token: {SUFFIX}, {SUFFIX_NUM}, {SEQ2} or {SEQ3}.';
        }
        $literal = preg_replace('/\{[A-Z0-9_]+\}/', '', $pattern);
        if (preg_match('/[^A-Za-z0-9._-]/', $literal) || str_contains($literal, '{') || str_contains($literal, '}')) {
            $errors[] = 'Outside the tokens, only letters, numbers, dash, dot and underscore are allowed.';
        }
        if (strlen($pattern) > 50) {
            $errors[] = 'The pattern is too long.';
        }

        return $errors;
    }

    /** Problems that stop a batch number being made for this product (missing codes etc.). */
    public static function productProblems(Product $product, ?string $pattern = null): array
    {
        $pattern ??= (string) Setting::get('batch_number_pattern');
        $p = [];
        if (preg_match('/\{BRAND2?\}/', $pattern)) {
            if (! $product->brand) {
                $p[] = "{$product->name} has no brand set.";
            } elseif ($why = CodeRules::brandProblem($product->brand->short_code)) {
                $p[] = "{$product->brand->name}: {$why}";
            }
        }
        if (preg_match('/\{PRODUCT3?\}/', $pattern) && ($why = CodeRules::productProblem($product->short_code))) {
            $p[] = "{$product->name}: {$why}";
        }
        if (str_contains($pattern, '{TYPE}') && ! self::typeCode($product)) {
            $p[] = "{$product->name} has no product family, so it has no type code.";
        }

        return $p;
    }

    public static function typeCode(Product $product): ?string
    {
        $map = (array) Setting::get('family_type_codes', []);

        return $product->family && ! empty($map[$product->family]) ? strtoupper((string) $map[$product->family]) : null;
    }

    /** Render with a given counter value (for previews and generation). */
    public static function render(string $pattern, Product $product, Carbon $date, int $counter): string
    {
        $d = $date->copy()->timezone(config('pbp.site_timezone'));
        $suffix = $counter <= 1 ? '' : self::letters($counter);
        $map = [
            'BRAND' => (string) $product->brand?->short_code, 'BRAND2' => (string) $product->brand?->short_code,
            'PRODUCT' => (string) $product->short_code, 'PRODUCT3' => (string) $product->short_code,
            'TYPE' => (string) self::typeCode($product),
            'PRODUCT_CODE' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $product->code)),
            'YYYY' => $d->format('Y'), 'YY' => $d->format('y'), 'MM' => $d->format('m'), 'DD' => $d->format('d'), 'YYMMDD' => $d->format('ymd'),
            'SEQ2' => str_pad((string) $counter, 2, '0', STR_PAD_LEFT), 'SEQ3' => str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
            'SUFFIX' => $suffix, 'SUFFIX_NUM' => $counter <= 1 ? '' : '-'.str_pad((string) $counter, 2, '0', STR_PAD_LEFT),
        ];

        return preg_replace_callback('/\{([A-Z0-9_]+)\}/', fn ($m) => $map[$m[1]] ?? '', $pattern);
    }

    /** Suffix letters skip look-alikes I and O. 2 -> B, 3 -> C ... 24 -> Z, 25 -> ZB ... (first batch has no letter). */
    public const SUFFIX_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    public static function letters(int $n): string
    {
        $a = self::SUFFIX_ALPHABET;
        $len = strlen($a);
        $out = '';
        while ($n > $len) {
            $out .= 'Z';
            $n -= $len - 1;
        }

        return $out.$a[$n - 1];
    }

    public static function preview(?Product $product = null, ?string $pattern = null): string
    {
        $pattern ??= (string) Setting::get('batch_number_pattern');
        $product ??= tap(new Product(['name' => 'Example', 'code' => 'example', 'family' => 'preroll', 'short_code' => '5BR']), function ($p) {
            $p->setRelation('brand', new \App\Models\Brand(['name' => 'Static', 'short_code' => 'ST']));
        });

        return self::render($pattern, $product, now(), 1).' (2nd that day: '.self::render($pattern, $product, now(), 2).')';
    }

    /** Must be called inside the transaction that creates the batch. */
    public function next(Product $product, ?Carbon $date = null): string
    {
        $pattern = (string) Setting::get('batch_number_pattern');
        if ($errors = self::validatePattern($pattern)) {
            throw GateFailed::because('The batch number pattern in Settings is not valid: '.implode(' ', $errors));
        }
        if ($p = self::productProblems($product, $pattern)) {
            throw GateFailed::because(...array_merge($p, ['An admin needs to fill this in under Setup → Brands / Products before the batch can start.']));
        }
        $date ??= now();
        $scope = self::scopeKey($pattern, $product, $date);
        for ($i = 0; $i < 500; $i++) {
            $n = PublicId::nextValue($scope);
            $candidate = self::render($pattern, $product, $date, $n);
            // A pattern change or an admin override may already have used this number: skip it.
            if (! Batch::where('batch_number', $candidate)->exists()) {
                return $candidate;
            }
        }
        throw GateFailed::because('Could not find a free batch number.');
    }

    /** Counter scope: the number with the counter blanked out (one counter per product per day for the default pattern). */
    private static function scopeKey(string $pattern, Product $product, Carbon $date): string
    {
        return 'batchno:'.self::render(preg_replace('/\{(SEQ2|SEQ3|SUFFIX|SUFFIX_NUM)\}/', '#', $pattern), $product, $date, 1);
    }

    /** The number the next batch of this product would get right now (does not use up a number). */
    public static function peek(Product $product, ?Carbon $date = null): ?string
    {
        $pattern = (string) Setting::get('batch_number_pattern');
        if (self::validatePattern($pattern) || self::productProblems($product, $pattern)) {
            return null;
        }
        $date ??= now();
        $n = (int) \Illuminate\Support\Facades\DB::table('id_sequences')->where('prefix', self::scopeKey($pattern, $product, $date))->value('last_value');
        for ($i = 1; $i <= 500; $i++) {
            $candidate = self::render($pattern, $product, $date, $n + $i);
            if (! Batch::where('batch_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return null;
    }

    /** The batch already using this number, any upper/lower case ("abc-1" = "ABC-1"). */
    public static function taken(string $number): ?Batch
    {
        $n = trim($number);

        return $n === '' ? null : Batch::whereRaw('LOWER(batch_number) = ?', [mb_strtolower($n)])->first();
    }

    /**
     * A batch number typed on Start a new batch (Andy, Nov 17: "we are creating the new batches ... type
     * in our own batch numbers"). Any format; trimmed; required; at most 60 characters; no line breaks
     * or control characters; never a duplicate of an existing batch number (any case).
     */
    public static function typedProblem(string $number): ?string
    {
        $n = trim($number);
        if ($n === '') {
            return 'Type the batch number.';
        }
        if (mb_strlen($n) > 60) {
            return 'A batch number can be at most 60 characters.';
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $n)) {
            return 'A batch number cannot contain line breaks or tabs.';
        }
        if ($b = self::taken($n)) {
            return "Batch number \"{$n}\" is already in the Batch Log".($b->batch_number !== $n ? " (as \"{$b->batch_number}\")" : '').'. Type a different number.';
        }

        return null;
    }

    public static function validateOverride(string $number): ?string
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,59}$/', $number)) {
            return 'A batch number can only use letters, numbers, dash, dot and underscore (2–60 characters).';
        }
        if (Batch::where('batch_number', $number)->exists()) {
            return "Batch number {$number} is already used.";
        }

        return null;
    }
}
