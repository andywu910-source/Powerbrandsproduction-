<?php

namespace Tests\Feature;

use App\Console\Commands\ImportSheetBatches;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class SheetImportTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,labels']);
        Brand::create(['name' => 'Bodega Boyz', 'short_code' => 'BB']);
        $sheet = fn ($id, $brand, $prod, $strain, $qty, $bc = '') => ['date' => '11/05/2026', 'brand' => $brand, 'product' => $prod, 'strain' => $strain, 'qty' => $qty, 'batch_id' => $id, 'barcode' => $bc, 'lab' => 'Kaycha', 'status' => 'Added to bill'];
        $rows = [
            ['row' => 2, 'batch_number' => 'NPRPC912~2', 'legacy_batch_id' => 'NPRPC912', 'brand_spelling' => 'Noizey ', 'brand' => 'Noizey', 'product' => 'Prerolls - purple', 'strain' => 'Pink Cloud', 'qty' => 5000, 'qty_text' => null,
                'barcode' => null, 'date' => '2025-09-08', 'exp_date' => null, 'lab' => '', 'notes' => 'Same Batch ID NPRPC912 as sheet row(s) 340; kept as a separate batch', 'sheet' => $sheet('NPRPC912', 'Noizey ', 'Prerolls - purple', 'Pink Cloud', '5000'), 'flags' => []],
            ['row' => 340, 'batch_number' => 'NPRPC912~340', 'legacy_batch_id' => 'NPRPC912', 'brand_spelling' => 'Noizey', 'brand' => 'Noizey', 'product' => '1g Preroll', 'strain' => 'Pink Cloud', 'qty' => 1393, 'qty_text' => null,
                'barcode' => '1A4120300000596000001172', 'date' => '2026-06-01', 'exp_date' => '2027-06-01', 'lab' => 'Kaycha', 'notes' => 'Same Batch ID NPRPC912 as sheet row(s) 2; kept as a separate batch', 'sheet' => $sheet('NPRPC912', 'Noizey', '1g Preroll', 'Pink Cloud', '1,393', '1A4120300000596000001172'), 'flags' => []],
            ['row' => 266, 'batch_number' => 'MM35SB527', 'legacy_batch_id' => 'MM35SB527 - FAILED LAB', 'brand_spelling' => 'bodega boyz', 'brand' => 'Bodega Boyz', 'product' => '3.5g Flower', 'strain' => 'Sour Banana', 'qty' => null, 'qty_text' => 'R&D',
                'barcode' => null, 'date' => null, 'exp_date' => null, 'lab' => '', 'notes' => 'Batch ID in the sheet: "MM35SB527 - FAILED LAB"; FAILED LAB; QTY in the sheet: "R&D" (not a number)', 'sheet' => $sheet('MM35SB527 - FAILED LAB', 'bodega boyz', '3.5g Flower', 'Sour Banana', 'R&D'), 'flags' => []],
            ['row' => 400, 'batch_number' => 'BAD NUMBER', 'legacy_batch_id' => 'BAD NUMBER', 'brand_spelling' => 'X', 'brand' => 'X', 'product' => 'Y', 'strain' => '', 'qty' => 1, 'qty_text' => null,
                'barcode' => null, 'date' => null, 'exp_date' => null, 'lab' => '', 'notes' => '', 'sheet' => $sheet('BAD NUMBER', 'X', 'Y', '', '1'), 'flags' => []],
        ];
        $data = ['import_id' => 'sheet-test', 'source' => ['title' => 'AR/Distro Pickup', 'tab' => 'Remediation/Lab'], 'rows' => $rows,
            'skipped' => [['row' => 459, 'reason' => 'words']], 'checksum' => hash('sha256', json_encode(ImportSheetBatches::sortKeys($rows), JSON_UNESCAPED_SLASHES))];
        $this->file = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($this->file, json_encode($data));
    }

    private function run_(string $mode): string
    {
        Artisan::call('batches:import-sheet', ['--file' => $this->file, "--$mode" => true]);

        return Artisan::output();
    }

    public function test_dry_run_changes_nothing(): void
    {
        $before = [Batch::count(), Brand::count(), Product::count()];
        $out = $this->run_('dry-run');
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertMatchesRegularExpression('/created\s+3/', $out);
        $this->assertMatchesRegularExpression('/skipped_invalid\s+1/', $out);
        $this->assertSame($before, [Batch::count(), Brand::count(), Product::count()]);
    }

    public function test_apply_is_idempotent_audited_and_searchable(): void
    {
        $out = $this->run_('apply');
        $this->assertMatchesRegularExpression('/created\s+3/', $out);
        $this->assertMatchesRegularExpression('/with_barcode\s+1/', $out);
        $this->assertStringContainsString('brands created: 1 (Noizey)', $out);
        $this->assertSame(1, Brand::where('name', 'Bodega Boyz')->count(), 'existing brand reused (spelling "bodega boyz")');
        $b = Batch::where('batch_number', 'NPRPC912~340')->firstOrFail();
        $this->assertSame(['imported', 'sheet', 340, '1A4120300000596000001172', 1393], [$b->status, $b->source, $b->sheet_row, $b->barcode_number, $b->target_units]);
        $this->assertNull($b->mmp_id);
        $this->assertFalse((bool) $b->product->active);
        $this->assertTrue((bool) $b->product->needs_review);
        $a = AuditLog::where('action', 'sheet_import')->where('record_id', $b->id)->firstOrFail();
        $this->assertSame(340, $a->details['sheet_row']);
        $this->assertSame('sheet import', $a->details['source']);
        $mm = Batch::where('batch_number', 'MM35SB527')->firstOrFail();
        $this->assertNull($mm->target_units);
        $this->assertStringContainsString('FAILED LAB', $mm->import_note);

        $out = $this->run_('verify');
        $this->assertStringContainsString('VERIFY OK', $out);
        $this->assertStringContainsString('3 from this import', $out);

        // Again: nothing new.
        $out = $this->run_('apply');
        $this->assertMatchesRegularExpression('/created\s+0/', $out);
        $this->assertMatchesRegularExpression('/skipped_exists\s+3/', $out);
        $this->assertSame(3, Batch::where('import_id', 'sheet-test')->count());

        // Findable in the new dropdown search (strain, partial, ~row) and the pages work.
        foreach (['pink cl', 'NPRPC912', 'noiz', '596000001172', 'sour ban'] as $q) {
            $ids = array_column($this->actingAs($this->sae)->getJson('/labels/batches?q='.rawurlencode($q))->json('items'), 'batch_number');
            $this->assertNotEmpty($ids, $q);
        }
        $this->actingAs($this->sae)->get('/batch-log')->assertOk()->assertSee('NPRPC912~340');
        $this->actingAs($this->sae)->get('/batch-log/NPRPC912~340')->assertOk()->assertSee('Imported from the Google Sheet')->assertSee('row 340');
        $this->actingAs($this->sae)->get('/batch-log/MM35SB527')->assertOk()->assertSee('MM35SB527 - FAILED LAB');
        $this->actingAs($this->sae)->get('/labels/new?batch_id='.$b->id)->assertOk()->assertSee('value="1A4120300000596000001172"', false)->assertDontSee('not QC-released');
        $this->actingAs($this->sae)->get('/production/NPRPC912~340')->assertRedirect();
        $this->actingAs($this->sae)->get('/production')->assertOk()->assertDontSee('NPRPC912');
    }

    public function test_edited_file_is_refused_and_undo_voids(): void
    {
        $d = json_decode(file_get_contents($this->file), true);
        $d['rows'][0]['qty'] = 9;
        $bad = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($bad, json_encode($d));
        Artisan::call('batches:import-sheet', ['--file' => $bad, '--apply' => true]);
        $this->assertStringContainsString('checksum', Artisan::output());
        $this->assertSame(0, Batch::where('source', 'sheet')->count());

        $this->run_('apply');
        $out = $this->run_('undo');
        $this->assertStringContainsString('voided 3 batches', $out);
        $this->assertSame(3, Batch::where('import_id', 'sheet-test')->where('status', 'void')->count());
        $this->assertSame([], $this->actingAs($this->sae)->getJson('/labels/batches?q=pink')->json('items'));
    }
}
