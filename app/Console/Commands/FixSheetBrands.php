<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off brand fix after the Nov 17 sheet import (Andy: "these are correct: Saavi, Kitty Creme,
 * static x 5 boro, Hai (Otherwse)").
 *   1. Renames brand Savvi -> Saavi and Kitty Kreme -> Kitty Creme, and the old spelling inside the
 *      names of THAT brand's products.
 *   2. Creates brand "Static x 5 Boro" and repoints every sheet-imported batch whose sheet brand was
 *      "Static 5 Boro" / "static x 5 boro".
 *   3. Creates brand "Hai (Otherwse)" and repoints every sheet-imported batch whose sheet brand was
 *      "Hai (otherwse)" (they were mapped to Otherwise).
 *   For 2 and 3: a product CREATED by the import and used only by those batches moves to the new brand;
 *   any other product (pre-existing, used by production) is left alone and a matching new product
 *   (inactive, needs review) is created under the new brand for those batches.
 * --dry-run shows every change and rolls back; --apply runs it in one transaction (all or nothing),
 * audited (action brand_fix + the usual create/update entries); --verify checks the result.
 * Idempotent: running --apply again changes nothing.
 */
class FixSheetBrands extends Command
{
    protected $signature = 'brands:fix-sheet-spellings {--dry-run : show every change, then roll back} {--apply : make the changes}
        {--verify : check the result} {--by= : admin email recorded (default: first active admin)}';

    protected $description = 'One-off: Saavi / Kitty Creme renames and Static x 5 Boro / Hai (Otherwse) brands for imported batches';

    public const FIX_ID = 'brandfix-20261117';

    /** old brand name => correct name */
    public const RENAMES = ['Savvi' => 'Saavi', 'Kitty Kreme' => 'Kitty Creme'];

    /** new brand => compact sheet spellings (lowercase, letters and digits only) */
    public const SPLITS = ['Static x 5 Boro' => ['static5boro', 'staticx5boro'], 'Hai (Otherwse)' => ['haiotherwse', 'haiotherwise']];

    private array $lines = [];
    private array $counts = [];

    public static function compact(?string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $s));
    }

    public function handle(): int
    {
        $modes = array_keys(array_filter(['dry-run' => $this->option('dry-run'), 'apply' => $this->option('apply'), 'verify' => $this->option('verify')]));
        if (count($modes) !== 1) {
            $this->error('Choose exactly one of --dry-run, --apply or --verify.');

            return self::INVALID;
        }
        $by = $this->option('by') ? User::where('email', $this->option('by'))->first() : User::where('role', User::ROLE_ADMIN)->where('is_active', true)->orderBy('id')->first();
        if (! $by) {
            $this->error('No admin user to record.');

            return self::INVALID;
        }
        if ($modes[0] === 'verify') {
            return $this->verify();
        }
        $dry = $modes[0] === 'dry-run';
        try {
            DB::transaction(function () use ($by, $dry) {
                $this->run_($by);
                Audit::log('brand_fix', null, ['fix_id' => self::FIX_ID, 'counts' => $this->counts, 'changes' => $this->lines, 'by' => $by->email]);
                if ($dry) {
                    throw new FixDryRun();
                }
            });
        } catch (FixDryRun) {
        } catch (\Throwable $e) {
            $this->error(($dry ? 'DRY RUN' : 'APPLY').' failed, nothing changed: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->line(($dry ? 'DRY RUN (rolled back, nothing changed)' : 'APPLIED (one transaction)').' · '.self::FIX_ID);
        foreach ($this->lines as $l) {
            $this->line('  '.$l);
        }
        $this->line('COUNTS '.json_encode($this->counts));

        return self::SUCCESS;
    }

    private function c(string $k, int $n = 1): void
    {
        $this->counts[$k] = ($this->counts[$k] ?? 0) + $n;
    }

    private function brandNamed(string $name, bool $compact = false)
    {
        $f = $compact ? fn ($b) => self::compact($b->name) === self::compact($name) : fn ($b) => mb_strtolower(trim($b->name)) === mb_strtolower(trim($name));

        return Brand::all()->filter($f)->values();
    }

    private function run_(User $by): void
    {
        foreach (['brands_renamed', 'brands_already_renamed', 'products_renamed', 'brands_created', 'products_moved', 'products_cloned', 'batches_repointed', 'batches_already_ok'] as $k) {
            $this->counts[$k] = 0;
        }
        // 1. Renames
        foreach (self::RENAMES as $old => $new) {
            $olds = $this->brandNamed($old, true);
            $news = $this->brandNamed($new);
            if ($olds->count() > 1) {
                throw new \RuntimeException("More than one brand looks like \"$old\": ".$olds->pluck('name')->implode(', '));
            }
            if ($olds->count() === 1 && $news->isNotEmpty() && $news->first()->id !== $olds->first()->id) {
                throw new \RuntimeException("Both \"{$olds->first()->name}\" and \"$new\" exist; merge them by hand.");
            }
            $brand = $olds->first() ?? $news->first();
            if (! $brand) {
                throw new \RuntimeException("Brand \"$old\" not found.");
            }
            if ($brand->name !== $new) {
                $this->lines[] = "brand #{$brand->id} renamed: \"{$brand->name}\" -> \"$new\"";
                $brand->update(['name' => $new]);
                $this->c('brands_renamed');
            } else {
                $this->lines[] = "brand #{$brand->id} \"$new\": already renamed";
                $this->c('brands_already_renamed');
            }
            $pat = '/'.preg_quote($old, '/').'/iu';
            foreach (Product::where('brand_id', $brand->id)->orderBy('id')->get() as $p) {
                if (preg_match($pat, $p->name)) {
                    $to = preg_replace($pat, $new, $p->name);
                    $this->lines[] = "  product #{$p->id} renamed: \"{$p->name}\" -> \"$to\"";
                    $p->update(['name' => $to]);
                    $this->c('products_renamed');
                }
            }
        }

        // 2 + 3. New brands for imported batches by their sheet spelling
        $sheet = Batch::with('product.brand')->where('source', 'sheet')->orderBy('sheet_row')->get();
        foreach (self::SPLITS as $newName => $spellings) {
            $hits = $sheet->filter(fn ($b) => in_array(self::compact($this->spelling($b)), $spellings, true))->values();
            $existing = $this->brandNamed($newName);
            $target = $existing->first();
            if (! $target) {
                $target = Brand::create(['name' => $newName, 'needs_review' => false, 'active' => true, 'recorded_by_user_id' => $by->id]);
                $this->lines[] = "brand #{$target->id} created: \"$newName\"";
                $this->c('brands_created');
            }
            $this->counts['batches_'.self::compact($newName)] = $hits->count();
            $ids = $hits->pluck('id')->all();
            $done = [];
            $moved = [];
            foreach ($hits as $b) {
                $b->refresh();
                $p = $b->product;
                if ((int) $p->brand_id === (int) $target->id && isset($moved[$p->id])) {
                    $this->lines[] = "  batch {$b->batch_number} (row {$b->sheet_row}): now \"$newName\" via its product #{$p->id}";
                    $this->c('batches_repointed');
                    continue;
                }
                if ((int) $p->brand_id === (int) $target->id) {
                    $this->lines[] = "  batch {$b->batch_number} (row {$b->sheet_row}): already under \"$newName\"";
                    $this->c('batches_already_ok');
                    continue;
                }
                $usedElsewhere = Batch::where('product_id', $p->id)->whereNotIn('id', $ids)->exists();
                if ($p->source === 'sheet_import' && ! $usedElsewhere) {
                    $from = $p->brand?->name;
                    $p->update(['brand_id' => $target->id]);
                    $moved[$p->id] = true;
                    $this->lines[] = "  product #{$p->id} \"{$p->name}\" (created by the import) moved: \"$from\" -> \"$newName\"";
                    $this->c('products_moved');
                    $this->lines[] = "  batch {$b->batch_number} (row {$b->sheet_row}): now \"$newName\" via its product #{$p->id}";
                    $this->c('batches_repointed');
                    continue;
                }
                $clone = $done[$p->id] ?? $this->cloneFor($p, $target, $by, $b);
                $done[$p->id] = $clone;
                $b->update(['product_id' => $clone->id]);
                $this->lines[] = "  batch {$b->batch_number} (row {$b->sheet_row}): product #{$p->id} \"{$p->brand?->name} / {$p->name}\" -> #{$clone->id} \"$newName / {$clone->name}\"";
                $this->c('batches_repointed');
            }
        }
    }

    /** The brand as written in the sheet: the batch's sheet_fields, else the import audit entry. */
    private function spelling(Batch $b): string
    {
        $s = trim((string) (($b->sheet_fields ?? [])['brand'] ?? ''));
        if ($s !== '') {
            return $s;
        }
        $a = AuditLog::where('action', 'sheet_import')->where('record_type', 'batch')->where('record_id', $b->id)->first();

        return trim((string) ($a?->details['brand_spelling'] ?? ''));
    }

    private function cloneFor(Product $p, Brand $target, User $by, Batch $b): Product
    {
        $code = 'fix-'.substr(sha1($target->id.'|'.$p->id), 0, 16);
        if ($hit = Product::where('code', $code)->first()) {
            return $hit;
        }
        $old = (string) $p->brand?->name;
        $name = $old !== '' && stripos($p->name, $old) === 0 ? $target->name.substr($p->name, strlen($old)) : $p->name;
        $copy = collect($p->getAttributes())->only(['short_code', 'family', 'product_type', 'form_code', 'unit_net_qty', 'unit_net_uom', 'hardware_sku', 'case_pack_qty', 'label_type_text', 'serving_type'])->all();
        $c = Product::create(array_merge($copy, ['code' => $code, 'name' => $name, 'brand_id' => $target->id, 'needs_review' => true, 'active' => false, 'source' => 'brand_fix',
            'review_note' => "Created by the Nov 17 brand fix for imported batches whose sheet brand is \"{$target->name}\" (copy of product #{$p->id} \"{$old} / {$p->name}\", which is unchanged). Check it before using it for production.",
            'recorded_by_user_id' => $by->id]));
        $this->lines[] = "  product #{$c->id} created: \"{$target->name} / $name\" (inactive, needs review; copy of #{$p->id} \"$old / {$p->name}\", left unchanged)";
        $this->c('products_cloned');

        return $c;
    }

    private function verify(): int
    {
        $ok = true;
        $say = function (bool $pass, string $msg) use (&$ok) {
            $ok = $ok && $pass;
            $this->line(($pass ? '  OK    ' : '  FAIL  ').$msg);
        };
        foreach (self::RENAMES as $old => $new) {
            $say($this->brandNamed($old, true)->isEmpty(), "no brand spelled \"$old\"");
            $b = $this->brandNamed($new)->first();
            $say((bool) $b, "brand \"$new\" exists".($b ? " (#{$b->id}, ".Product::where('brand_id', $b->id)->count().' products)' : ''));
            if ($b) {
                $left = Product::where('brand_id', $b->id)->get()->filter(fn ($p) => stripos($p->name, $old) !== false)->count();
                $say($left === 0, "no \"$new\" product name still says \"$old\"");
            }
        }
        $sheet = Batch::with('product.brand')->where('source', 'sheet')->get();
        foreach (self::SPLITS as $newName => $spellings) {
            $hits = $sheet->filter(fn ($b) => in_array(self::compact($this->spelling($b)), $spellings, true));
            $t = $this->brandNamed($newName)->first();
            $wrong = $hits->filter(fn ($b) => ! $t || (int) $b->product?->brand_id !== (int) $t->id)->count();
            $say($t && $wrong === 0, "\"$newName\": {$hits->count()} imported batches, all under it".($wrong ? " ($wrong are not)" : ''));
            if ($t) {
                $moved = Product::where('brand_id', $t->id)->whereNotIn('source', ['sheet_import', 'brand_fix'])->count();
                $say($moved === 0, "\"$newName\" has no pre-existing production products moved into it");
                $site = Batch::whereIn('product_id', Product::where('brand_id', $t->id)->pluck('id'))->where('source', '!=', 'sheet')->count();
                $say($site === 0, "no production (site) batch points to \"$newName\"");
            }
        }
        $this->line($ok ? 'VERIFY OK' : 'VERIFY FAILED');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}

class FixDryRun extends \RuntimeException {}
