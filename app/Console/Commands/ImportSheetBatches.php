<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit;
use App\Services\PublicId;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-off import of the Google Sheet 'AR/Distro Pickup' › Remediation/Lab into the Batch Log, from a
 * data file prepared and reviewed in advance (no Google access needed on the server).
 *   php artisan batches:import-sheet --file=remediation-lab-import.json --dry-run
 *   php artisan batches:import-sheet --file=remediation-lab-import.json --apply
 *   php artisan batches:import-sheet --file=remediation-lab-import.json --undo   (voids this import's batches)
 * Idempotent: a batch number already in the Batch Log (any case) is skipped. Everything runs in one
 * database transaction (all or nothing). Every created batch is audit-logged with the sheet row.
 * Brands are matched by name (the data file's suggested brand, then the sheet spelling); a brand is
 * created only when neither exists. Products are matched by brand + name; otherwise a product is
 * created inactive and marked "needs review" (it is not offered for new production).
 */
class ImportSheetBatches extends Command
{
    protected $signature = 'batches:import-sheet {--file= : the import data file (JSON)} {--dry-run : show what would happen; change nothing}
        {--apply : create the batches} {--undo : void the batches this import created (records are never deleted)}
        {--verify : check every row of the file is in the Batch Log and a sample is found by the Labels batch search} {--by= : admin email recorded as the importer (default: first active admin)}';

    protected $description = 'One-off import of the Google Sheet batches (Remediation/Lab) into the Batch Log';

    private array $brandsCreated = [];
    private array $productsCreated = [];
    private array $brandCache = [];
    private array $productsMatched = [];
    private array $brandsFuzzy = [];
    private array $productsByBrand = [];
    private const JUNK = ['labeling', 'labelling', 'coating', 'packaging', 'package', 'case', 'shrinkband', 'qc', 'sample', 'regrind', 'defects', 'final', 'fix', 'test', 'rnd', 'r&d'];
    private const STOP = ['g', 'gram', 'grams', 'pr', 'preroll', 'prerolls', 'pre', 'roll', 'flower', 'infused', 'the', 'x', 'of', 'de', 'la', 'and', 'bag', 'jar', 'jars', 'mylar'];

    /** Lowercase word tokens without the brand's own words and filler words. */
    private static function tokens(string $s, array $drop = []): array
    {
        $s = mb_strtolower(preg_replace('/(\d+(?:\.\d+)?)\s*(?:g|gram|grams)\b/i', ' ', $s));
        $w = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_diff($w, self::STOP, $drop)));
    }

    private static function size(string $s): ?string
    {
        return preg_match('/(\d+(?:\.\d+)?)\s*(?:g|gram|grams)\b/i', $s, $m) ? rtrim(rtrim(number_format((float) $m[1], 3, '.', ''), '0'), '.') : null;
    }

    /**
     * An existing product of the brand that is clearly this one: every strain word is in its name,
     * no stage words (Coating, Labeling...), and the size matches when both have one. Only a single,
     * clear best match is used; otherwise a new product is created for review.
     */
    private function matchProduct(Brand $brand, string $strain, string $type): ?Product
    {
        $drop = self::tokens($brand->name);
        $want = self::tokens($strain, $drop);
        if (! $want) {
            return null;
        }
        $size = self::size($type.' '.$strain);
        $this->productsByBrand[$brand->id] ??= Product::where('brand_id', $brand->id)->where('source', '!=', 'sheet_import')->get();
        $scored = [];
        foreach ($this->productsByBrand[$brand->id] as $p) {
            $have = self::tokens($p->name, $drop);
            if (array_intersect($have, self::JUNK) || array_diff($want, $have)) {
                continue;
            }
            $psize = self::size($p->name) ?? ($p->unit_net_qty ? rtrim(rtrim(number_format((float) $p->unit_net_qty, 3, '.', ''), '0'), '.') : null);
            if ($size !== null && $psize !== $size) {
                continue;   // a different (or unknown) size is a different product
            }
            $scored[] = [count(array_diff($have, $want)), $p];
        }
        usort($scored, fn ($a, $b) => $a[0] <=> $b[0]);
        if (! $scored || (isset($scored[1]) && $scored[1][0] === $scored[0][0])) {
            return null;
        }

        return $scored[0][1];
    }
    private array $productCache = [];

    private static function norm(?string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));
    }

    public function handle(): int
    {
        $modes = array_filter(['dry-run' => $this->option('dry-run'), 'apply' => $this->option('apply'), 'undo' => $this->option('undo'), 'verify' => $this->option('verify')]);
        if (count($modes) !== 1) {
            $this->error('Choose exactly one of --dry-run, --apply, --verify or --undo.');

            return self::INVALID;
        }
        $mode = array_key_first($modes);
        $data = json_decode((string) @file_get_contents((string) $this->option('file')), true);
        if (! is_array($data) || empty($data['import_id']) || ! isset($data['rows']) || ! is_array($data['rows'])) {
            $this->error('The data file is missing or not an import file.');

            return self::INVALID;
        }
        if (($data['checksum'] ?? null) !== hash('sha256', json_encode(self::sortKeys($data['rows']), JSON_UNESCAPED_SLASHES))) {
            $this->error('The data file checksum does not match its rows (was it edited?).');

            return self::INVALID;
        }
        $by = $this->option('by') ? User::where('email', $this->option('by'))->first() : User::where('role', User::ROLE_ADMIN)->where('is_active', true)->orderBy('id')->first();
        if (! $by) {
            $this->error('No admin user to record as the importer.');

            return self::INVALID;
        }
        $importId = (string) $data['import_id'];

        if ($mode === 'undo') {
            return $this->undo($importId, $by);
        }
        if ($mode === 'verify') {
            return $this->verify($data, $importId, $by);
        }

        $counts = ['rows_in_file' => count($data['rows']), 'created' => 0, 'with_barcode' => 0, 'without_barcode' => 0, 'skipped_exists' => 0, 'skipped_invalid' => 0];
        $skips = [];
        $sample = null;
        $run = function () use ($data, $importId, $by, $mode, &$counts, &$skips, &$sample) {
            $existing = Batch::pluck('batch_number')->mapWithKeys(fn ($n) => [self::norm($n) => $n])->all();
            foreach ($data['rows'] as $r) {
                $bn = trim((string) ($r['batch_number'] ?? ''));
                $why = $this->invalid($r, $bn);
                if ($why) {
                    $counts['skipped_invalid']++;
                    $skips[] = "row {$r['row']}: $why";
                    continue;
                }
                if (isset($existing[self::norm($bn)])) {
                    $counts['skipped_exists']++;
                    $skips[] = "row {$r['row']}: $bn already in the Batch Log";
                    continue;
                }
                $brand = $this->brand($r, $by);
                $product = $this->product($r, $brand, $by);
                $tz = config('pbp.site_timezone');
                $batch = Batch::create([
                    'public_id' => PublicId::next('BPR'), 'batch_number' => $bn, 'product_id' => $product->id,
                    'mmp_id' => null, 'mmp_version' => null, 'target_units' => $r['qty'] ?? null, 'status' => 'imported', 'log_status' => 'finished', 'current_step' => 1,
                    'started_at' => ! empty($r['date']) ? Carbon::parse($r['date'].' 12:00', $tz)->utc() : null,
                    'coa_lab' => mb_substr((string) ($r['lab'] ?? ''), 0, 190) ?: null,
                    'barcode_number' => $r['barcode'] ?: null,
                    'expiration_date' => $r['exp_date'] ?? null,
                    'source' => 'sheet', 'import_id' => $importId, 'sheet_row' => (int) $r['row'], 'legacy_batch_id' => mb_substr((string) ($r['legacy_batch_id'] ?? ''), 0, 120),
                    'sheet_fields' => $r['sheet'] ?? [], 'import_note' => trim('Imported from Google Sheet "'.($data['source']['title'] ?? '').'" › '.($data['source']['tab'] ?? '').' row '.$r['row'].'.'.(($r['notes'] ?? '') !== '' ? ' '.$r['notes'].'.' : '')),
                    'recorded_by_user_id' => $by->id,
                ]);
                Audit::log('sheet_import', $batch, ['source' => 'sheet import', 'import_id' => $importId, 'sheet_row' => (int) $r['row'], 'batch_number' => $bn,
                    'legacy_batch_id' => $r['legacy_batch_id'] ?? null, 'brand_spelling' => $r['brand_spelling'] ?? null, 'brand' => $brand->name, 'product' => $product->name, 'by' => $by->email]);
                $existing[self::norm($bn)] = $bn;
                $counts['created']++;
                $counts[$r['barcode'] ? 'with_barcode' : 'without_barcode']++;
                $sample ??= $batch->batch_number.' · '.$brand->name.' · '.$product->name;
            }
            if ($mode === 'dry-run') {
                throw new DryRunRollback;
            }
        };
        try {
            DB::transaction($run);
        } catch (DryRunRollback) {
            // dry run: everything above was rolled back
        }

        $this->line(($mode === 'dry-run' ? 'DRY RUN (rolled back, nothing changed)' : 'APPLIED').' · import '.$importId.' · by '.$by->email);
        foreach ($counts as $k => $v) {
            $this->line(sprintf('  %-18s %d', $k, $v));
        }
        $this->line('  rows skipped in the file (no Batch ID / not an ID): '.count($data['skipped'] ?? []));
        $this->line('  brands created: '.count($this->brandsCreated).($this->brandsCreated ? ' ('.implode(', ', $this->brandsCreated).')' : ''));
        if ($this->brandsFuzzy) {
            $this->line('  brands matched by a near spelling: '.implode(', ', array_unique($this->brandsFuzzy)));
        }
        $this->line('  products matched to existing: '.count($this->productsMatched).' · created (inactive, needs review): '.count($this->productsCreated));
        foreach (array_slice($skips, 0, 50) as $s) {
            $this->line('  skip  '.$s);
        }
        if ($sample) {
            $this->line('  sample: '.$sample);
        }

        return self::SUCCESS;
    }

    public static function sortKeys($v)
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map([self::class, 'sortKeys'], $v);
    }

    private function invalid(array $r, string $bn): ?string
    {
        if ($bn === '' || mb_strlen($bn) > 60 || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9.\/_#~-]{1,59}$/', $bn)) {
            return "Batch number \"$bn\" is not valid";
        }
        if (self::norm($r['brand'] ?? '') === '' || self::norm($r['product'] ?? '') === '') {
            return 'no brand or product';
        }
        if (($r['qty'] ?? null) !== null && ($r['qty'] < 0 || $r['qty'] > \App\Support\OcmSampling::MAX_UNITS)) {
            return 'QTY over 150,000';
        }
        if (! empty($r['barcode']) && ! preg_match('/^1A4[0-9A-Z]{21}$/', $r['barcode'])) {
            return 'barcode is not a 1A4 tag';
        }

        return null;
    }

    private function brand(array $r, User $by): Brand
    {
        $cache = &$this->brandCache;
        $want = trim((string) $r['brand']);
        $spell = trim((string) ($r['brand_spelling'] ?? ''));
        $k = self::norm($want).'|'.self::norm($spell);
        if (isset($cache[$k])) {
            return $cache[$k];
        }
        $all = Brand::all();
        $compact = fn ($v) => preg_replace('/[^a-z0-9]/', '', self::norm($v));
        $b = $all->first(fn ($x) => self::norm($x->name) === self::norm($want)) ?? $all->first(fn ($x) => self::norm($x->name) === self::norm($spell))
            ?? $all->first(fn ($x) => $compact($x->name) === $compact($want) || $compact($x->name) === $compact($spell));
        if (! $b) {   // one letter off ("Sticky Smalls" / "Sticky Smallz"), and only if that is the single such brand
            $near = $all->filter(fn ($x) => strlen($compact($want)) >= 5 && (levenshtein($compact($x->name), $compact($want)) <= 1 || levenshtein($compact($x->name), $compact($spell)) <= 1));
            $b = $near->count() === 1 ? $near->first() : null;
            if ($b) {
                $this->brandsFuzzy[] = "$spell -> {$b->name}";
            }
        }
        if (! $b) {
            $b = Brand::create(['name' => $want, 'needs_review' => true, 'active' => true, 'recorded_by_user_id' => $by->id]);
            $this->brandsCreated[] = $want;
        }

        return $cache[$k] = $b;
    }

    private function product(array $r, Brand $brand, User $by): Product
    {
        $cache = &$this->productCache;
        $strain = trim(preg_replace('/\s+/u', ' ', (string) ($r['strain'] ?? '')));
        $type = trim(preg_replace('/\s+/u', ' ', (string) ($r['product'] ?? '')));
        $name = trim($strain.' '.$type);
        $code = 'sheet-'.substr(sha1($brand->id.'|'.self::norm($name)), 0, 16);
        $k = $brand->id.'|'.self::norm($name);
        if (isset($cache[$k])) {
            return $cache[$k];
        }
        $hit = Product::where('code', $code)->first() ?? $this->matchProduct($brand, $strain, $type);
        if ($hit && $hit->code !== $code) {
            $this->productsMatched[$hit->id] = $hit->name;
        }
        if (! $hit) {
            $hit = Product::create(['code' => $code, 'name' => mb_substr($name, 0, 190), 'brand_id' => $brand->id, 'needs_review' => true,
                'review_note' => 'Created by the Google Sheet import (row '.$r['row'].'): check the name, type and size before using it for production.',
                'source' => 'sheet_import', 'active' => false, 'recorded_by_user_id' => $by->id]);
            $this->productsCreated[] = $brand->name.' '.$name;
        }

        return $cache[$k] = $hit;
    }

    private function verify(array $data, string $importId, User $by): int
    {
        $log = Batch::pluck('batch_number')->mapWithKeys(fn ($n) => [self::norm($n) => true])->all();
        $missing = array_values(array_filter($data['rows'], fn ($r) => ! isset($log[self::norm($r['batch_number'] ?? '')]) && ! $this->invalid($r, trim((string) $r['batch_number']))));
        $mine = Batch::where('import_id', $importId)->count();
        $total = Batch::count();
        $this->line("VERIFY · import $importId · Batch Log has $total batches · $mine from this import · rows in file ".count($data['rows']).' · missing '.count($missing));
        foreach (array_slice($missing, 0, 20) as $r) {
            $this->line("  missing  row {$r['row']}: {$r['batch_number']}");
        }
        // Sample: found by the Labels batch dropdown search (signed in, in-process, in-memory session).
        $sample = Batch::where('import_id', $importId)->where('status', '!=', 'void')->whereNotNull('barcode_number')->orderBy('sheet_row')->first()
            ?? Batch::where('import_id', $importId)->where('status', '!=', 'void')->orderBy('sheet_row')->first();
        $found = false;
        if ($sample) {
            $by->must_change_password = false;   // in memory only
            config(['session.driver' => 'array']);
            \Illuminate\Support\Facades\Auth::guard('web')->setUser($by);
            foreach (array_filter([mb_substr($sample->batch_number, 0, 6), (($st = trim((string) ($sample->sheet_fields['strain'] ?? ''))) !== '' ? $st : $sample->product?->name), $sample->barcode_number ? substr($sample->barcode_number, -8) : null]) as $q) {
                $res = app(\Illuminate\Contracts\Http\Kernel::class)->handle(\Illuminate\Http\Request::create('/labels/batches?q='.rawurlencode($q), 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']));
                $ids = array_column((array) (json_decode((string) $res->getContent(), true)['items'] ?? []), 'id');
                $ok = $res->getStatusCode() === 200 && in_array($sample->id, $ids, true);
                $found = $found || $ok;
                $this->line(($ok ? '  OK    ' : '  FAIL  ')."search '$q' finds {$sample->batch_number} (".count($ids).' hits)');
            }
        }
        $pass = ! $missing && $mine > 0 && $found;
        $this->line($pass ? 'VERIFY OK' : 'VERIFY FAILED');

        return $pass ? self::SUCCESS : self::FAILURE;
    }

    private function undo(string $importId, User $by): int
    {
        $n = 0;
        DB::transaction(function () use ($importId, $by, &$n) {
            foreach (Batch::where('import_id', $importId)->where('status', '!=', 'void')->get() as $b) {
                $b->update(['status' => 'void', 'void_reason' => 'Undo of sheet import '.$importId, 'voided_by' => $by->id, 'voided_at' => now()]);
                Audit::log('sheet_import_undo', $b, ['import_id' => $importId, 'sheet_row' => $b->sheet_row]);
                $n++;
            }
        });
        $this->line("UNDO · import $importId · voided $n batches (records are never deleted; they no longer appear in the label search). For a full undo restore the backup taken before the import.");

        return self::SUCCESS;
    }
}

class DryRunRollback extends \RuntimeException {}
