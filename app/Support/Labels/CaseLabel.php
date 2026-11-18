<?php

namespace App\Support\Labels;

use Picqer\Barcode\Types\TypeCode128;

/**
 * Lays out the case / carton label (config/pbp_labels.php) and draws it as a PDF
 * (one label per page, exact 216 x 144 pt pages for thermal printers) or as SVG for
 * the on-screen preview. Both come from the same layout, so the preview matches print.
 */
class CaseLabel
{
    /** Line roles. Data lines get their text from the form unless the text was edited. */
    public const DATA_ROLES = ['batch', 'total', 'cases', 'per_case'];

    private ?array $explicit = null;

    /**
     * @param array<int, string|array{text:string, role?:string, wrap?:bool}> $lines text lines top to bottom ('' = blank line)
     * @param array{size_with_barcode?: float, size_without_barcode?: float} $sizes starting font sizes (Case box uses bigger text)
     */
    public function __construct(private array $lines, private ?string $barcode = null, private array $sizes = []) {}

    /** A label drawn from an edited layout (see toLayout()); the barcode value always comes from the batch. */
    public static function fromLayout(array $layout, ?string $barcode): self
    {
        $l = new self([], $barcode);
        $l->explicit = self::sanitize($layout, $barcode !== null && $barcode !== '');
        if ($l->explicit['barcode']) {
            $l->explicit['barcode'] = self::clampBarcode($l->explicit['barcode'], $barcode);
        }

        return $l;
    }

    public static function cfg(string $key, mixed $default = null): mixed
    {
        return config("pbp_labels.$key", $default);
    }

    /** Number of Code 128 modules for a value (bars + spaces). */
    public static function modules(string $value): int
    {
        return (int) (new TypeCode128)->getBarcode($value)->getWidth();
    }

    /** Narrowest barcode that still scans: modules x minimum module width (2 printer dots at 203 dpi). */
    public static function minBarcodeWidth(string $value): float
    {
        return round(self::modules($value) * (float) self::cfg('barcode.min_module', 0.71), 2);
    }

    /** Split one line into two (word boundary, most even split). */
    public static function wrap2(string $text, string $font, float $size, float $max): array
    {
        $words = preg_split('/\s+/u', trim($text));
        if (count($words) < 2) {
            return [$text];
        }
        $best = null;
        for ($i = 1; $i < count($words); $i++) {
            $a = implode(' ', array_slice($words, 0, $i));
            $b = implode(' ', array_slice($words, $i));
            $wa = FontMetrics::width($a, $font, $size);
            $wb = FontMetrics::width($b, $font, $size);
            $score = max($wa, $wb) + ($wb > $wa ? 0.01 : 0);   // most even split (so the text stays as big as it can); on a tie the longer half goes first
            if ($best === null || $score < $best[0]) {
                $best = [$score, $a, $b];
            }
        }

        return [$best[1], $best[2]];
    }

    /** Normalise role-tagged input lines. @return array<int, array{text:string, role:string, wrap:bool}> */
    private function tagged(): array
    {
        return array_map(fn ($l) => is_array($l)
            ? ['text' => (string) ($l['text'] ?? ''), 'role' => (string) ($l['role'] ?? 'custom'), 'wrap' => (bool) ($l['wrap'] ?? false)]
            : ['text' => (string) $l, 'role' => 'custom', 'wrap' => false], $this->lines);
    }

    /** Layout: font sizes, text positions and barcode bars. All in points, y from the bottom. */
    public function layout(): array
    {
        return $this->explicit ? $this->explicitLayout() : $this->autoLayout();
    }

    private function autoLayout(): array
    {
        $W = (float) self::cfg('page.width'); $H = (float) self::cfg('page.height');
        $t = self::cfg('text');
        $t = array_merge($t, array_intersect_key($this->sizes, array_flip(['size_with_barcode', 'size_without_barcode'])));
        $bar = $this->barcode !== null && $this->barcode !== '';
        $base = (float) ($bar ? $t['size_with_barcode'] : $t['size_without_barcode']);
        $in = $this->tagged();
        // Wrap long lines that may wrap (the product line) onto 2 lines before shrinking anything.
        $lines = [];
        foreach ($in as $l) {
            if ($l['wrap'] && FontMetrics::width($l['text'], $t['font'], $base) > $t['max_width']) {
                foreach (self::wrap2($l['text'], $t['font'], $base, $t['max_width']) as $part) {
                    $lines[] = ['text' => $part, 'role' => $l['role']];
                }
            } else {
                $lines[] = ['text' => $l['text'], 'role' => $l['role']];
            }
        }
        $size = $base;
        $longest = fn (float $s) => max(array_map(fn ($l) => FontMetrics::width($l['text'], $t['font'], $s), $lines ?: [['text' => '']]));
        while ($size > $t['min_size'] && $longest($size) > $t['max_width']) {
            $size = round($size - 0.25, 2);
        }
        $lead = $size * $t['leading'];
        $n = count($lines);
        if ($bar) {
            $y = $H - $t['top'] - $size * ($t['first_line_drop'] ?? 0.78);
        } else {
            $block = ($n - 1) * $lead + $size * 0.718;
            $y = ($H + $block) / 2 - $size * 0.718;
        }
        $text = [];
        foreach ($lines as $l) {
            if ($l['text'] !== '') {
                $text[] = ['x' => (float) $t['left'], 'y' => round($y, 3), 'text' => $l['text'], 'size' => $size, 'role' => $l['role']];
            }
            $y -= $lead;
        }
        $b = self::cfg('barcode');
        $bc = $bar ? ['x' => (float) $b['x'], 'y' => (float) $b['y'], 'w' => (float) $b['width'], 'h' => (float) $b['height']] : null;

        return $this->finish($W, $H, $t['font'], $size, $text, $bc, $longest($size) <= $t['max_width']);
    }

    private function explicitLayout(): array
    {
        $W = (float) self::cfg('page.width'); $H = (float) self::cfg('page.height');
        $font = (string) self::cfg('text.font');
        $text = [];
        $fits = true;
        foreach ($this->explicit['lines'] as $l) {
            if ($l['text'] === '' && ! $l['edited']) {
                continue;   // an empty data line (e.g. no cases yet); a line the user emptied stays in the layout
            }
            $text[] = ['x' => $l['x'], 'y' => $l['y'], 'text' => $l['text'], 'size' => $l['size'], 'role' => $l['role'], 'edited' => $l['edited']];
            if ($l['text'] === '') {
                continue;
            }
            $wd = FontMetrics::width($l['text'], $font, $l['size']);
            if ($l['x'] < 0 || $l['x'] + $wd > $W + 0.01 || $l['y'] - $l['size'] * 0.21 < 0 || $l['y'] + $l['size'] * 0.718 > $H + 0.01) {
                $fits = false;
            }
        }
        $size = $text ? max(array_column($text, 'size')) : (float) self::cfg('text.size_with_barcode');
        $bc = ($this->barcode !== null && $this->barcode !== '') ? $this->explicit['barcode'] : null;

        return ['explicit' => true] + $this->finish($W, $H, $font, $size, $text, $bc, $fits);
    }

    private function finish(float $W, float $H, string $font, float $size, array $text, ?array $bc, bool $fits): array
    {
        $out = ['width' => $W, 'height' => $H, 'font' => $font, 'size' => $size, 'text' => $text, 'bars' => [], 'barcode' => null,
            'fits' => $fits, 'overlaps_barcode' => false, 'barcode_box' => null];
        if ($bc) {
            $b = self::cfg('barcode');
            $code = (new TypeCode128)->getBarcode($this->barcode);
            $scale = $bc['w'] / max(1, $code->getWidth());
            $x = $bc['x'];
            foreach ($code->getBars() as $bb) {
                $w = $bb->getWidth() * $scale;
                if ($bb->isBar() && $w > 0) {
                    $out['bars'][] = [round($x, 4), $bc['y'], round($w, 4), $bc['h']];
                }
                $x += $w;
            }
            $ty = $bc['y'] - ((float) $b['y'] - (float) $b['text_y']);   // number sits the same distance under the bars
            $tw = FontMetrics::width($this->barcode, $b['text_font'], $b['text_size']);
            $out['barcode'] = ['value' => $this->barcode, 'font' => $b['text_font'], 'size' => (float) $b['text_size'], 'x' => round($bc['x'] + ($bc['w'] - $tw) / 2, 3),
                'y' => round($ty, 3), 'module' => round($scale, 4)];
            $out['barcode_box'] = $bc;
            foreach ($text as $t) {   // any text box overlapping the bars or the number
                if ($t['text'] === '') {
                    continue;
                }
                $tw2 = FontMetrics::width($t['text'], $font, $t['size']);
                $top = $t['y'] + $t['size'] * 0.718; $bot = $t['y'] - $t['size'] * 0.21;
                if ($t['x'] < $bc['x'] + $bc['w'] && $t['x'] + $tw2 > $bc['x'] && $bot < $bc['y'] + $bc['h'] && $top > $ty - 1) {
                    $out['overlaps_barcode'] = true;
                }
            }
            if ($bc['x'] < 0 || $bc['x'] + $bc['w'] > $W + 0.01 || $ty < 0 || $bc['y'] + $bc['h'] > $H + 0.01) {
                $out['fits'] = false;
            }
        }

        return $out;
    }

    /** The editable layout for this label (what the on-label editor changes and the print log stores). */
    public function toLayout(): array
    {
        $L = $this->layout();
        $lines = array_map(fn ($t) => ['role' => $t['role'] ?? 'custom', 'text' => $t['text'], 'x' => round($t['x'], 2), 'y' => round($t['y'], 2), 'size' => (float) $t['size'],
            'edited' => (bool) ($t['edited'] ?? false)], $L['text']);

        return ['v' => 1, 'w' => $L['width'], 'h' => $L['height'], 'lines' => array_values($lines),
            'barcode' => $L['barcode_box'] ? array_map(fn ($v) => round((float) $v, 2), $L['barcode_box']) : null,
            'barcode_min_w' => $this->barcode ? self::minBarcodeWidth($this->barcode) : null];
    }

    /** Clean an edited layout: sizes 6-24 pt, at most 14 lines of 120 characters, barcode never narrower than it scans. */
    public static function sanitize(array $in, bool $hasBarcode, ?string $barcode = null): array
    {
        $W = (float) self::cfg('page.width'); $H = (float) self::cfg('page.height');
        $min = (float) self::cfg('text.min_size', 6); $max = (float) self::cfg('editor.max_size', 24);
        $lines = [];
        foreach (array_slice((array) ($in['lines'] ?? []), 0, 14) as $l) {
            if (! is_array($l)) {
                continue;
            }
            $size = max($min, min($max, round((float) ($l['size'] ?? 10.5), 2)));
            $x = max(0, min($W - 1, round((float) ($l['x'] ?? 9), 2)));
            $y = max(1, min($H, round((float) ($l['y'] ?? 72), 2)));
            $role = in_array($l['role'] ?? '', array_merge(['product', 'type', 'custom'], self::DATA_ROLES), true) ? $l['role'] : 'custom';
            $edited = filter_var($l['edited'] ?? false, FILTER_VALIDATE_BOOLEAN);
            // A typed line break makes more lines below, one line height apart.
            foreach (explode("\n", str_replace("\r", '', (string) ($l['text'] ?? ''))) as $k => $part) {
                $lines[] = ['role' => $k ? 'custom' : $role, 'text' => mb_substr(trim(preg_replace('/[\t ]+/u', ' ', $part)), 0, 120),
                    'x' => $x, 'y' => round(max(1, $y - $k * $size * (float) self::cfg('text.leading', 1.135)), 2), 'size' => $size, 'edited' => $k ? true : $edited];
            }
        }
        $bc = null;
        if ($hasBarcode) {
            $b = self::cfg('barcode');
            $d = is_array($in['barcode'] ?? null) ? $in['barcode'] : [];
            $w = (float) ($d['w'] ?? $b['width']);
            $h = max(15.0, min(80.0, (float) ($d['h'] ?? $b['height'])));
            $bc = ['x' => round((float) ($d['x'] ?? $b['x']), 2), 'y' => round((float) ($d['y'] ?? $b['y']), 2), 'w' => round($w, 2), 'h' => round($h, 2)];
        }

        return ['lines' => array_slice($lines, 0, 20), 'barcode' => $bc];
    }

    /** Barcode box clamped so it scans and stays on the label. */
    public static function clampBarcode(array $bc, string $value): array
    {
        $W = (float) self::cfg('page.width'); $H = (float) self::cfg('page.height');
        $gap = (float) self::cfg('barcode.y') - (float) self::cfg('barcode.text_y');
        $bc['w'] = max(self::minBarcodeWidth($value), min($W, $bc['w']));
        $bc['x'] = max(0, min($W - $bc['w'], $bc['x']));
        $bc['y'] = max($gap + 1, min($H - $bc['h'], $bc['y']));

        return array_map(fn ($v) => round((float) $v, 2), $bc);
    }

    private static function pdfString(string $s): string
    {
        return '('.strtr(FontMetrics::encode($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']).')';
    }

    /** PDF with $copies identical pages (all pages share one content stream). */
    public function pdf(int $copies = 1, array $meta = []): string
    {
        $L = $this->layout();
        $f = fn ($v) => rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');
        $ops = "0 g\n";
        foreach ($L['bars'] as [$x, $y, $w, $h]) {
            $ops .= $f($x).' '.$f($y).' '.$f($w).' '.$f($h)." re\n";
        }
        if ($L['bars']) {
            $ops .= "f\n";
        }
        $ops .= "BT\n";
        $cur = null;
        foreach ($L['text'] as $t) {
            if ($t['text'] === '') {
                continue;
            }
            if ($cur !== $t['size']) {
                $ops .= '/F1 '.$f($t['size'])." Tf\n";
                $cur = $t['size'];
            }
            $ops .= '1 0 0 1 '.$f($t['x']).' '.$f($t['y']).' Tm '.self::pdfString($t['text'])." Tj\n";
        }
        if ($L['barcode']) {
            $bc = $L['barcode'];
            $ops .= '/F2 '.$f($bc['size']).' Tf 1 0 0 1 '.$f($bc['x']).' '.$f($bc['y']).' Tm '.self::pdfString($bc['value'])." Tj\n";
        }
        $ops .= "ET\n";

        $copies = max(1, $copies);
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $first = 7;
        for ($i = 0; $i < $copies; $i++) {
            $kids[] = ($first + $i).' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Count '.$copies.' /Kids ['.implode(' ', $kids).'] >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[5] = '<< /Length '.strlen($ops)." >>\nstream\n".$ops."endstream";
        $info = ['Producer' => 'PowerBrands Productions', 'Title' => $meta['title'] ?? 'Case label'] + array_filter(['Subject' => $meta['subject'] ?? null]);
        $objs[6] = '<< '.implode(' ', array_map(fn ($k, $v) => "/$k ".self::pdfString((string) $v), array_keys($info), $info)).' /CreationDate (D:'.gmdate('YmdHis')."Z) >>";
        for ($i = 0; $i < $copies; $i++) {
            $objs[$first + $i] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$f($L['width']).' '.$f($L['height']).'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents 5 0 R >>';
        }
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "$n 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = max(array_keys($objs)) + 1;
        $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($n = 1; $n < $count; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $pdf .= "trailer\n<< /Size $count /Root 1 0 R /Info 6 0 R >>\nstartxref\n$xref\n%%EOF\n";

        return $pdf;
    }

    /** SVG preview (same layout; y flipped for SVG). */
    public function svg(): string
    {
        $L = $this->layout();
        $H = $L['height'];
        $e = fn ($s) => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$L['width'].' '.$H.'" width="'.($L['width'] * 2).'" height="'.($H * 2).'" class="pb-label-svg" style="stroke:none;fill:none" role="img" aria-label="Label preview">';
        $s .= '<rect width="100%" height="100%" fill="#fff"/>';
        // Barcode first, text on top: a line dragged over the barcode stays grabbable in the editor.
        if ($L['barcode']) {
            $s .= '<g data-barcode="1">';
            $box = $L['barcode_box'];
            $s .= '<rect x="'.$box['x'].'" y="'.round($H - $box['y'] - $box['h'], 3).'" width="'.$box['w'].'" height="'.$box['h'].'" fill="#fff" fill-opacity="0"/>';
            foreach ($L['bars'] as [$x, $y, $w, $h]) {
                $s .= '<rect x="'.$x.'" y="'.round($H - $y - $h, 3).'" width="'.$w.'" height="'.$h.'" fill="#000"/>';
            }
            $bc = $L['barcode'];
            // Same left x as the PDF (no text-anchor), so preview and print match exactly.
            $s .= '<text x="'.$bc['x'].'" y="'.round($H - $bc['y'], 3).'" font-family="Helvetica, Arial, sans-serif" font-size="'.$bc['size'].'" fill="#000">'.$e($bc['value']).'</text>';
            $s .= '</g>';
        }

        $s .= '<g font-family="Helvetica, Arial, sans-serif" font-weight="700" fill="#000">';
        foreach ($L['text'] as $i => $t) {
            $s .= '<text data-line="'.$i.'" x="'.$t['x'].'" y="'.round($H - $t['y'], 3).'" font-size="'.$t['size'].'" xml:space="preserve">'.$e($t['text']).'</text>';
        }
        $s .= '</g>';
        return $s.'</svg>';
    }
}
