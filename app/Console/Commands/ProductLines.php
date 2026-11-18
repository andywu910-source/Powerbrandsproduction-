<?php

namespace App\Console\Commands;

use App\Services\ProductPicker;
use App\Support\ProductLine;
use Illuminate\Console\Command;

/** Read-only: how every active product appears in Start a batch (Brand › Product line › Flavor). */
class ProductLines extends Command
{
    protected $signature = 'products:lines {--unsorted : list only the products under "Other (not sorted yet)"} {--csv= : also write every product to this CSV file}';

    protected $description = 'Show the Brand › Product line › Flavor picker for active products (changes nothing)';

    public function handle(): int
    {
        $e = ProductPicker::entries();
        $sorted = array_filter($e, fn ($x) => $x['sorted']);
        $noSize = array_filter($sorted, fn ($x) => $x['no_size']);
        $hand = array_filter($sorted, fn ($x) => $x['source'] === 'set by hand');
        $noBrand = array_filter($e, fn ($x) => $x['brand'] === ProductPicker::NO_BRAND);
        $this->line(sprintf('Active products: %d · sorted into a line: %d (%d with a size, %d with no size in the name, %d set by hand) · under "%s": %d · no brand: %d',
            count($e), count($sorted), count($sorted) - count($noSize), count($noSize), count($hand), ProductLine::OTHER, count($e) - count($sorted), count($noBrand)));
        $why = [];
        foreach ($e as $x) {
            if (! $x['sorted']) {
                $why[preg_replace('/ \(.*$|"[^"]*"/', '', (string) $x['why'])][] = $x;
            }
        }
        foreach ($why as $w => $xs) {
            $this->line("  not sorted: $w: ".count($xs));
        }
        foreach ($e as $x) {
            if (! $this->option('unsorted') || ! $x['sorted']) {
                $this->line(sprintf('  #%-4d %s › %s › %s%s', $x['id'], $x['brand'], $x['line'], $x['flavor'], $x['sorted'] ? ($x['no_size'] ? '  [no size in the name]' : '') : '  ['.$x['why'].']'));
            }
        }
        if ($f = $this->option('csv')) {
            $h = fopen($f, 'w');
            fputcsv($h, ['product_id', 'product_name', 'brand', 'product_line', 'flavor', 'sorted', 'no_size', 'source', 'why_not_sorted']);
            foreach ($e as $x) {
                fputcsv($h, [$x['id'], $x['name'], $x['brand'], $x['line'], $x['flavor'], $x['sorted'] ? 'yes' : 'no', $x['no_size'] ? 'yes' : '', $x['source'], $x['why']]);
            }
            fclose($h);
            $this->line("CSV: $f");
        }

        return self::SUCCESS;
    }
}
