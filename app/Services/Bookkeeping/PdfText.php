<?php

namespace App\Services\Bookkeeping;

use Symfony\Component\Process\Process;

/**
 * Text from a PDF, using poppler's pdftotext when the server has it. Nothing is installed by the app:
 * without pdftotext this returns null and account detection falls back to the file name.
 */
class PdfText
{
    public function available(): bool
    {
        $bin = $this->binary();

        return $bin !== null && is_file($bin) && is_executable($bin);
    }

    public function extract(string $bytes): ?string
    {
        if (! $this->available()) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'bkpdf');
        try {
            file_put_contents($tmp, $bytes);
            // First 3 pages are enough for the account header.
            $p = new Process([$this->binary(), '-q', '-l', '3', '-layout', $tmp, '-']);
            $p->setTimeout(15);
            $p->run();

            return $p->isSuccessful() ? $p->getOutput() : null;
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    private function binary(): ?string
    {
        $b = (string) config('bookkeeping.pdftotext', '/usr/bin/pdftotext');

        return $b === '' ? null : $b;
    }
}
