<?php

namespace App\Services;

use App\Models\Product;
use App\Support\ProductLine;
use Illuminate\Support\Collection;

/**
 * Brand › Product line › Flavor for the Start-a-batch screen. Every ACTIVE product appears exactly
 * once and every (brand, line, flavor) is unique, so the three choices always resolve to exactly one
 * product. A product that cannot be split cleanly is listed under "Other (not sorted yet)" with its
 * own name (never guessed). Hand-set products.product_line / products.flavor always win.
 */
class ProductPicker
{
    public const NO_BRAND = '(No brand set)';

    /** @return array<int, array{id:int, brand_id:int, brand:string, line:string, flavor:string, sorted:bool, source:string, why:?string, no_size:bool, name:string}> */
    public static function entries(?Collection $products = null): array
    {
        $products ??= Product::with('brand')->where('active', true)->orderBy('name')->get();
        $out = [];
        foreach ($products as $p) {
            $brand = $p->brand?->name ?? self::NO_BRAND;
            $hand = trim((string) $p->product_line) !== '' && trim((string) $p->flavor) !== '';
            $r = $hand ? null : ProductLine::parse((string) $p->name, $p->brand?->name, $p->family);
            $out[] = $hand
                ? ['line' => trim($p->product_line), 'flavor' => trim($p->flavor), 'sorted' => true, 'source' => 'set by hand', 'why' => null, 'no_size' => false]
                    + ['id' => $p->id, 'brand_id' => (int) $p->brand_id, 'brand' => $brand, 'name' => $p->name]
                : ['line' => $r['ok'] ? $r['line'] : ProductLine::OTHER, 'flavor' => $r['ok'] ? $r['flavor'] : ($r['rest'] !== '' ? $r['rest'] : $p->name),
                    'sorted' => $r['ok'], 'source' => 'from the name', 'why' => $r['why'], 'no_size' => $r['ok'] && $r['no_size']]
                    + ['id' => $p->id, 'brand_id' => (int) $p->brand_id, 'brand' => $brand, 'name' => $p->name];
        }
        // Two products with the same brand + line + flavor cannot be told apart: neither is guessed.
        $groups = [];
        foreach ($out as $i => $e) {
            if ($e['sorted']) {
                $groups[ProductLine::key($e['brand_id'].'|'.$e['line'].'|'.$e['flavor'])][] = $i;
            }
        }
        foreach ($groups as $is) {
            if (count($is) > 1) {
                foreach ($is as $i) {
                    $others = array_map(fn ($j) => '#'.$out[$j]['id'].' "'.$out[$j]['name'].'"', array_diff($is, [$i]));
                    $out[$i] = array_merge($out[$i], ['sorted' => false, 'line' => ProductLine::OTHER, 'flavor' => ProductLine::stripBrand($out[$i]['name'], $out[$i]['brand']) ?: $out[$i]['name'],
                        'why' => 'same brand, line and flavor as '.implode(', ', $others)]);
                }
            }
        }
        // Inside "Other" the full names could still repeat: add the product number.
        $seen = [];
        foreach ($out as $i => $e) {
            $k = ProductLine::key($e['brand_id'].'|'.$e['line'].'|'.$e['flavor']);
            if (isset($seen[$k])) {
                $out[$i]['flavor'] .= ' (#'.$e['id'].')';
            }
            $seen[$k] = true;
        }
        usort($out, fn ($a, $b) => [$a['brand'] === self::NO_BRAND, mb_strtolower($a['brand']), $a['line'] === ProductLine::OTHER, self::lineSort($a['line']), mb_strtolower($a['flavor'])]
            <=> [$b['brand'] === self::NO_BRAND, mb_strtolower($b['brand']), $b['line'] === ProductLine::OTHER, self::lineSort($b['line']), mb_strtolower($b['flavor'])]);

        return $out;
    }

    /** Lines sorted by type, then size ("0.7g Shortiez Preroll" before "1.25g Chopped Cheese Preroll"). */
    private static function lineSort(string $line): string
    {
        $size = preg_match('/^(\d+(?:\.\d+)?)g/', $line, $m) ? sprintf('%08.3f', (float) $m[1]) : '99999.999';
        $type = preg_replace('/^\S*\d\S*\s+/', '', $line);

        return mb_strtolower($type).'|'.$size.'|'.mb_strtolower($line);
    }

    /** Compact data for the page: [brands..], [lines..], [items..]. */
    public static function forJs(array $ready = []): array
    {
        $items = [];
        foreach (self::entries() as $e) {
            $items[] = ['id' => $e['id'], 'b' => $e['brand'], 'l' => $e['line'], 'f' => $e['flavor'], 'n' => $e['name'], 's' => $e['sorted'] ? 1 : 0, 'r' => in_array($e['id'], $ready, true) ? 1 : 0];
        }

        return $items;
    }
}
