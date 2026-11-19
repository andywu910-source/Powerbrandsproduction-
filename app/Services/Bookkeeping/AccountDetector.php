<?php

namespace App\Services\Bookkeeping;

/**
 * Which bank account a statement belongs to (8455 main, 9529, 9511).
 *
 * 1. The file's contents (CSV text, or PDF text when pdftotext is on the server): masked numbers (xxxx8455,
 *    ****8455, ...8455), "account / acct / ending in 8455", or a long account number ending in the 4 digits.
 *    When the contents mention more than one of our accounts (e.g. a transfer line), the one that appears
 *    first and most often wins; a tie is left to the file name.
 * 2. The file name: exactly one of the three numbers, not part of a longer number.
 * Otherwise unidentified: the admin picks it.
 */
class AccountDetector
{
    public function __construct(private PdfText $pdf) {}

    /** @return array{account: ?string, source: ?string, note: string} */
    public function detect(string $name, string $ext, string $bytes): array
    {
        $accounts = array_map('strval', (array) config('bookkeeping.accounts', []));
        $text = $ext === 'pdf' ? $this->pdf->extract($bytes) : $bytes;
        $ambiguous = [];

        if (is_string($text) && $text !== '') {
            [$hit, $ambiguous] = self::fromText($text, $accounts);
            if ($hit) {
                return ['account' => $hit, 'source' => 'contents', 'note' => 'Found in the file'];
            }
        }
        $byName = self::fromName($name, $accounts);
        if ($byName) {
            return ['account' => $byName, 'source' => 'filename', 'note' => 'From the file name'];
        }
        $why = $ambiguous ? 'The file mentions '.implode(' and ', $ambiguous).'. Pick the account.'
            : ($ext === 'pdf' && ! $this->pdf->available() ? 'PDF text can\'t be read on this server and the file name has no account number. Pick the account.'
            : 'No account number found. Pick the account.');

        return ['account' => null, 'source' => null, 'note' => $why];
    }

    /** @return array{0: ?string, 1: string[]} [winner, accounts mentioned when ambiguous] */
    public static function fromText(string $text, array $accounts): array
    {
        $found = [];   // account => [count, first offset]
        $add = function (string $digits, int $at) use (&$found, $accounts) {
            $last4 = substr($digits, -4);
            if (in_array($last4, $accounts, true)) {
                $found[$last4] ??= [0, $at];
                $found[$last4][0]++;
                $found[$last4][1] = min($found[$last4][1], $at);
            }
        };
        $patterns = [
            '/(?:[x*•#]{2,}|\.{2,})[\s\-]*(\d{4})(?!\d)/iu',                                                 // xxxx8455, ****8455, ...8455
            '/(?:acct|account|a\/c|ending(?:\s+in)?|last\s+(?:4|four)(?:\s+digits)?)\b[^0-9\r\n]{0,20}(\d{4})(?![\d.,]\d)(?!\d)/iu',
            '/(?<![\d.,$])(\d{8,17})(?![\d]|[.,]\d)/u',                                                          // full account number
        ];
        foreach ($patterns as $re) {
            if (preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as [$digits, $at]) {
                    $add($digits, $at);
                }
            }
        }
        if (! $found) {
            return [null, []];
        }
        if (count($found) === 1) {
            return [(string) array_key_first($found), []];
        }
        uasort($found, fn ($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        $keys = array_map('strval', array_keys($found));
        [$top, $second] = array_values(array_slice($found, 0, 2));
        if ($top[0] > $second[0] && $top[1] <= $second[1]) {
            return [(string) $keys[0], []];
        }
        sort($keys);

        return [null, $keys];
    }

    public static function fromName(string $name, array $accounts): ?string
    {
        $hits = [];
        foreach ($accounts as $a) {
            if (preg_match('/(?<!\d)'.preg_quote($a, '/').'(?!\d)/', $name)) {
                $hits[] = $a;
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
    }
}
