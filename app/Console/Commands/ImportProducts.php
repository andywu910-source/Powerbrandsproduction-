<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Support\Import\CodeSuggester;
use App\Support\Import\ProductNameNormalizer as N;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the product master from Andy's Production Log ("Product List" tab, copied
 * into database/seeders/data/products.json). Merges spelling variants, keeps every
 * original spelling as an alias, suggests brand/product codes, and flags everything
 * "needs review". Safe to re-run: nothing existing is overwritten or duplicated.
 */
class ImportProducts extends Command
{
    protected $signature = 'pbp:import-products {file? : JSON file (default database/seeders/data/products.json)} {--dry-run : Only show the counts}';
    protected $description = 'Import products from the Production Log product list (dedupe + aliases + suggested codes, all flagged needs review)';

    public function handle(): int
    {
        $file = $this->argument('file') ?: database_path('seeders/data/products.json');
        $json = json_decode((string) @file_get_contents($file), true);
        $names = array_values(array_filter(array_map(fn ($v) => is_string($v) ? $v : (string) $v, (array) ($json['all_products'] ?? [])), fn ($v) => trim($v) !== ''));
        if (! $names) {
            $this->error("No product names found in {$file}.");

            return self::FAILURE;
        }

        N::$hints = array_change_key_case((array) ($json['family_hints'] ?? []), CASE_LOWER);
        $exact = array_unique($names);
        $groups = [];
        foreach ($exact as $n) {
            $groups[N::key($n)][] = $n;
        }
        // Keep every raw occurrence for picking the most-used spelling.
        $occ = [];
        foreach ($names as $n) {
            $occ[N::key($n)][] = $n;
        }

        $stats = ['values' => count($names), 'unique_exact' => count($exact), 'after_dedupe' => count($groups),
            'merged_groups' => count(array_filter($groups, fn ($g) => count($g) > 1)),
            'no_brand' => 0, 'no_family' => 0, 'task_like' => 0, 'created' => 0, 'existing' => 0, 'aliases_added' => 0, 'brands_created' => 0];

        foreach ($groups as $k => $spellings) {
            $canon = N::canonical($occ[$k]);
            $stats['no_brand'] += N::brandOf($canon) ? 0 : 1;
            $stats['no_family'] += N::familyOf($canon, $spellings) ? 0 : 1;
            $stats['task_like'] += N::looksLikeTask($canon) ? 1 : 0;
        }

        $this->table(['Count', 'Value'], collect($stats)->only(['values', 'unique_exact', 'after_dedupe', 'merged_groups', 'no_brand', 'no_family', 'task_like'])->map(fn ($v, $k) => [$k, $v])->values()->all());
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($groups, $occ, &$stats) {
            foreach ($groups as $k => $spellings) {
                $canon = N::canonical($occ[$k]);
                $existing = ProductAlias::where('alias_key', $k)->first()?->product;
                if (! $existing) {
                    $existing = ProductAlias::whereIn('alias_key', array_map(fn ($s) => N::key($s), $spellings))->first()?->product;
                }
                if ($existing) {
                    $stats['existing']++;
                    $product = $existing;
                } else {
                    $brandName = N::brandOf($canon);
                    $brand = null;
                    if ($brandName) {
                        $brand = Brand::firstWhere('name', $brandName);
                        if (! $brand) {
                            $brand = Brand::create(['name' => $brandName, 'needs_review' => true,
                                'short_code' => CodeSuggester::brand($brandName, Brand::whereNotNull('short_code')->pluck('short_code')->all())]);
                            $stats['brands_created']++;
                        }
                    }
                    $family = N::familyOf($canon, $spellings);
                    $task = N::looksLikeTask($canon);
                    $taken = Product::where('brand_id', $brand?->id)->whereNotNull('short_code')->pluck('short_code')->all();
                    $notes = ['Imported from the Production Log product list. Check the name, brand, family and codes.'];
                    if (count($spellings) > 1) $notes[] = 'Merged spellings: '.implode(' | ', $spellings).'.';
                    if (! $brandName) $notes[] = 'No brand found in the name.';
                    if (! $family) $notes[] = 'Product family could not be told from the name.';
                    if ($task) $notes[] = 'This looks like a work task (prep, formulation, labeling...), not a product. Deactivate it if so.';
                    $product = Product::create([
                        'code' => $this->uniqueSlug($canon), 'name' => $canon, 'brand_id' => $brand?->id,
                        'short_code' => CodeSuggester::product($canon, $brandName, $family, $taken),
                        'family' => $family, 'product_type' => $family,
                        'form_code' => $family ? (['preroll' => 'PR', 'vape' => 'VP', 'flower' => 'FL', 'rosin' => 'RS', 'green_mantis' => 'GM'][$family]) : null,
                        'needs_review' => true, 'review_note' => implode(' ', $notes), 'source' => 'production_log', 'active' => true,
                    ]);
                    $stats['created']++;
                }
                foreach (array_unique(array_merge($spellings, [$canon])) as $s) {
                    $aliasKey = $s === $canon ? $k : mb_strtolower(trim($s));
                    if (! ProductAlias::where('alias_key', $aliasKey)->exists()) {
                        ProductAlias::create(['product_id' => $product->id, 'alias' => $s, 'alias_key' => $aliasKey, 'source' => 'production_log']);
                        $stats['aliases_added']++;
                    }
                }
            }
        });

        $this->info("Products created: {$stats['created']}, already there: {$stats['existing']}, brands created: {$stats['brands_created']}, spellings saved as aliases: {$stats['aliases_added']}.");
        $this->line('Everything imported is marked "needs review" (Setup → Products).');

        return self::SUCCESS;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 50, '') ?: 'product';
        $slug = $base;
        $i = 2;
        while (Product::where('code', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
