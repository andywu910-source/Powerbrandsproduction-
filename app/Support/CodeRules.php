<?php

namespace App\Support;

use App\Models\Setting;

/** Brand / product short codes used in batch numbers (lengths + allowed characters are Settings). */
class CodeRules
{
    public static function charset(): string
    {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) Setting::get('code_charset', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789')));

        return $c !== '' ? count_chars($c, 3) : 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    }

    public static function brandLength(): int { return max(1, min(8, (int) Setting::get('brand_code_length', 2))); }
    public static function productLength(): int { return max(1, min(10, (int) Setting::get('product_code_length', 3))); }

    public static function problem(?string $code, int $length, string $what): ?string
    {
        $code = (string) $code;
        if ($code === '') {
            return "The {$what} code is missing.";
        }
        if (strlen($code) !== $length) {
            return "The {$what} code must be exactly {$length} characters.";
        }
        $bad = array_diff(str_split($code), str_split(self::charset()));
        if ($bad) {
            return "The {$what} code uses characters that aren't allowed (".implode(' ', array_unique($bad))."). Allowed: ".self::charset().'.';
        }

        return null;
    }

    public static function brandProblem(?string $code): ?string { return self::problem($code, self::brandLength(), 'brand'); }
    public static function productProblem(?string $code): ?string { return self::problem($code, self::productLength(), 'product'); }
}
