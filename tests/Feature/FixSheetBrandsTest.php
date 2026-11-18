<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class FixSheetBrandsTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private array $p = [];
    private Batch $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
        $mk = fn ($brand, $name, $src, $code) => Product::create(['code' => $code, 'name' => $name, 'brand_id' => $brand->id, 'source' => $src, 'active' => $src !== 'sheet_import', 'family' => 'preroll', 'unit_net_qty' => 1]);
        $savvi = Brand::create(['name' => 'Savvi']);
        $kitty = Brand::create(['name' => 'Kitty Kreme']);
        $static = Brand::create(['name' => 'Static']);
        $other = Brand::create(['name' => 'Otherwise']);
        Brand::create(['name' => 'Hai']);
        $this->p = [
            'savvi_pl' => $mk($savvi, 'Savvi Mac1 3.5g', 'production_log', 'sv1'),
            'savvi_imp' => $mk($savvi, 'Mac #1 3.5g Flower', 'sheet_import', 'sv2'),
            'kitty_pl' => $mk($kitty, 'Kitty Kreme Powerpuff 1g preroll', 'production_log', 'kk1'),
            'static_imp' => $mk($static, 'Trop cookies 1g Infused PR', 'sheet_import', 'st1'),
            'static_shared' => $mk($static, 'Shared 1g', 'sheet_import', 'st2'),
            'static_pl' => $mk($static, 'Static Blue 1g preroll', 'production_log', 'st3'),
            'other_pl' => $mk($other, 'Otherwise 7g OG Flower', 'production_log', 'ot1'),
        ];
        $base = (array) DB::table('batches')->where('id', $this->startBatch(100)->id)->first();
        $this->site = Batch::where('id', $base['id'])->first();
        DB::table('batches')->where('id', $base['id'])->update(['product_id' => $this->p['static_pl']->id]);   // production batch on a Static product
        unset($base['id']);
        $add = function ($bn, $row, $spelling, $product) use ($base) {
            DB::table('batches')->insert(array_merge($base, ['batch_number' => $bn, 'public_id' => 'BPR-F'.$row, 'status' => 'imported', 'source' => 'sheet', 'sheet_row' => $row,
                'product_id' => $this->p[$product]->id, 'sheet_fields' => json_encode(['brand' => $spelling, 'batch_id' => $bn])]));
        };
        $add('STC5B1~431', 431, 'Static 5 Boro', 'static_imp');
        $add('STC5B1~456', 456, 'Static 5 Boro', 'static_imp');
        $add('STC5B2', 440, 'static x 5 boro', 'static_pl');
        $add('STC5B3', 441, 'Static 5 Boro', 'static_shared');
        $add('STPLAIN', 442, 'Static', 'static_shared');
        $add('HAIPBOG', 364, 'Hai (otherwse)', 'other_pl');
        $add('SAAVI1', 300, 'Saavi', 'savvi_imp');
    }

    private function run_(string $opt): string
    {
        $code = Artisan::call('brands:fix-sheet-spellings', [$opt => true]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);

        return $out;
    }

    private function counts(string $out): array
    {
        preg_match('/COUNTS (\{.*\})/', $out, $m);

        return json_decode($m[1], true);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $before = [Brand::count(), Product::count(), Batch::pluck('product_id', 'id')->all(), Brand::pluck('name')->all()];
        $out = $this->run_('--dry-run');
        $this->assertStringContainsString('"Savvi" -> "Saavi"', $out);
        $this->assertSame($before, [Brand::count(), Product::count(), Batch::pluck('product_id', 'id')->all(), Brand::pluck('name')->all()]);
        $this->assertSame(0, AuditLog::where('action', 'brand_fix')->count());
        $this->assertSame(1, Artisan::call('brands:fix-sheet-spellings', ['--verify' => true]));
    }

    public function test_apply_fixes_brands_and_is_idempotent(): void
    {
        $c = $this->counts($this->run_('--apply'));
        $this->assertSame(2, $c['brands_renamed']);
        $this->assertSame(2, $c['products_renamed']);
        $this->assertSame(2, $c['brands_created']);
        $this->assertSame(1, $c['products_moved']);        // Trop cookies (import-created, only these batches)
        $this->assertSame(3, $c['products_cloned']);       // Static Blue (production), Shared (also used by a "Static" row), Otherwise OG
        $this->assertSame(5, $c['batches_repointed']);
        $this->assertSame(4, $c['batches_staticx5boro']);
        $this->assertSame(1, $c['batches_haiotherwse']);

        $this->assertSame('Saavi Mac1 3.5g', $this->p['savvi_pl']->fresh()->name);
        $this->assertSame('Mac #1 3.5g Flower', $this->p['savvi_imp']->fresh()->name);
        $this->assertSame('Kitty Creme Powerpuff 1g preroll', $this->p['kitty_pl']->fresh()->name);
        $this->assertSame(['Saavi', 'Kitty Creme'], [$this->p['savvi_pl']->fresh()->brand->name, $this->p['kitty_pl']->fresh()->brand->name]);

        $s5 = Brand::where('name', 'Static x 5 Boro')->firstOrFail();
        $hai = Brand::where('name', 'Hai (Otherwse)')->firstOrFail();
        $this->assertSame($s5->id, $this->p['static_imp']->fresh()->brand_id);
        $this->assertSame('Static', $this->p['static_pl']->fresh()->brand->name, 'production product never moves');
        $this->assertSame('Static', $this->p['static_shared']->fresh()->brand->name);
        $this->assertSame('Otherwise', $this->p['other_pl']->fresh()->brand->name);
        $this->assertSame($this->p['static_pl']->id, $this->site->fresh()->product_id, 'production batch untouched');
        $this->assertSame('Static', Batch::where('batch_number', 'STPLAIN')->first()->product->brand->name);
        $b2 = Batch::where('batch_number', 'STC5B2')->first()->product;
        $this->assertSame([$s5->id, false, true, 'brand_fix', 'Static x 5 Boro Blue 1g preroll', 'preroll'], [$b2->brand_id, $b2->active, $b2->needs_review, $b2->source, $b2->name, $b2->family]);
        $h = Batch::where('batch_number', 'HAIPBOG')->first()->product;
        $this->assertSame([$hai->id, 'Hai (Otherwse) 7g OG Flower'], [$h->brand_id, $h->name]);
        $this->assertSame(1, AuditLog::where('action', 'brand_fix')->count());
        $this->assertTrue(AuditLog::where('record_type', 'batch')->where('action', 'update')->exists());

        $this->assertSame(0, Artisan::call('brands:fix-sheet-spellings', ['--verify' => true]), Artisan::output());
        $again = $this->counts($this->run_('--apply'));
        foreach (['brands_renamed', 'products_renamed', 'brands_created', 'products_moved', 'products_cloned', 'batches_repointed'] as $k) {
            $this->assertSame(0, $again[$k], $k);
        }
        $this->assertSame(2, $again['brands_already_renamed']);
        $this->assertSame(5, $again['batches_already_ok']);
    }

    public function test_refuses_when_both_spellings_exist(): void
    {
        Brand::create(['name' => 'Saavi']);
        $n = Brand::count();
        $this->assertSame(1, Artisan::call('brands:fix-sheet-spellings', ['--apply' => true]));
        $this->assertStringContainsString('merge them by hand', Artisan::output());
        $this->assertSame($n, Brand::count());
        $this->assertSame('Savvi Mac1 3.5g', $this->p['savvi_pl']->fresh()->name);
    }
}
