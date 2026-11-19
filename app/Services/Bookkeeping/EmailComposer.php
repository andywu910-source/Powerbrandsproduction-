<?php

namespace App\Services\Bookkeeping;

use App\Models\BookkeepingSetting;

class EmailComposer
{
    public static function signatureHtml(): string
    {
        $stored = BookkeepingSetting::get('signature_html', null);
        if (is_string($stored)) {
            return self::sanitize($stored);
        }

        return self::sanitize((string) config('bookkeeping.signature_html', ''));
    }

    public static function signatureIsStored(): bool
    {
        return is_string(BookkeepingSetting::get('signature_html', null));
    }

    /** @return array{0: string, 1: string} text, html. Template text is escaped; only the signature is HTML. */
    public static function render(string $body, string $signatureHtml): array
    {
        $signatureHtml = self::sanitize($signatureHtml);
        $textSig = trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/li)\b[^>]*>/i', "\n", $signatureHtml) ?? $signatureHtml)));
        $text = str_replace('[signature]', $textSig, $body);

        $html = '';
        foreach (explode('[signature]', $body) as $i => $chunk) {
            if ($i > 0) {
                $html .= $signatureHtml;
            }
            $html .= nl2br(e($chunk), false);
        }

        return [$text, $html];
    }

    /** @param  array<int, string>  $invoiceNumbers */
    public static function attachmentIndicator(array $invoiceNumbers): string
    {
        $names = [];
        foreach ($invoiceNumbers as $number) {
            $number = trim((string) $number);
            if ($number === '') {
                continue;
            }
            $names[] = self::pdfName($number);
        }

        return $names ? 'Attached: '.implode(', ', $names) : 'Attached: open invoice PDF(s)';
    }

    public static function pdfName(string $invoiceNumber): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoiceNumber) ?: 'invoice';
        $safe = trim($safe, '-');

        return ($safe !== '' ? $safe : 'invoice').'.pdf';
    }

    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = strip_tags($html, '<a><br><p><div><span><strong><b><em><i><u><img><table><thead><tbody><tr><td><th><ul><ol><li><hr>');
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? '';
        $html = preg_replace_callback('/\s(href|src)\s*=\s*("|\')(.*?)\2/i', function (array $m): string {
            $attr = strtolower($m[1]);
            $url = trim(html_entity_decode($m[3], ENT_QUOTES));
            $ok = $attr === 'href'
                ? (bool) preg_match('#^(https://|mailto:)#i', $url)
                : (bool) preg_match('#^https://#i', $url);
            if (! $ok) {
                return '';
            }

            return ' '.$attr.'="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'"';
        }, $html) ?? '';

        return $html;
    }
}
