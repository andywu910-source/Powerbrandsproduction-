<?php

namespace App\Support\Labels;

/** Character widths of the PDF core fonts (from the Adobe AFM files shipped with dompdf). */
class FontMetrics
{
    private static array $cache = [];

    /** @return array<int,int> WinAnsi code => width in 1/1000 em */
    public static function widths(string $font): array
    {
        if (isset(self::$cache[$font])) {
            return self::$cache[$font];
        }
        $path = base_path("vendor/dompdf/dompdf/lib/fonts/{$font}.afm");
        $w = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^C (\d+) ; WX (\d+) ;/', $line, $m)) {
                $w[(int) $m[1]] = (int) $m[2];
            }
        }

        return self::$cache[$font] = $w;
    }

    /** Text as Windows-1252 bytes (what the PDF WinAnsiEncoding font expects). */
    public static function encode(string $text): string
    {
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $out;
    }

    public static function width(string $text, string $font, float $size): float
    {
        $w = self::widths($font);
        $sum = 0;
        foreach (str_split(self::encode($text)) as $ch) {
            $sum += $w[ord($ch)] ?? 556;
        }

        return $sum * $size / 1000;
    }
}
