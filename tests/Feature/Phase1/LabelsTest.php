<?php

namespace Tests\Feature\Phase1;

use App\Models\AuditLog;
use App\Models\LabelPrint;
use App\Models\Product;
use App\Services\LabelService;
use App\Support\Labels\CaseLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class LabelsTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,labels']);
        $this->brand->update(['name' => 'Bodega Boyz', 'requires_barcode' => true]);
        $this->product->update(['name' => 'Watermelon Zkittlez 0.7g', 'label_type_text' => 'Shortiez Preroll']);
    }

    private function fields(array $over = []): array
    {
        return $over + ['title' => 'Bodega Boyz Watermelon Zkittlez 0.7g', 'type_text' => 'Shortiez Preroll', 'batch_label' => 'Batch #', 'batch_number' => 'BBWZK261117',
            'total' => '320', 'count_label' => 'Count', 'cases' => '', 'case_label' => 'Case', 'per_case' => '10', 'per_case_label' => 'Count per case box',
            'barcode_on' => '1', 'barcode_value' => '1A41203000005960000001707'];
    }

    public function test_case_math_even_and_partial(): void
    {
        $this->assertSame(['cases' => 32, 'full' => 32, 'remainder' => 0, 'text' => null], LabelService::caseMath(320, 10));
        $this->assertSame(['cases' => 32, 'full' => 31, 'remainder' => 5, 'text' => '31 full cases + 1 case of 5'], LabelService::caseMath(315, 10));
        $this->assertSame(1, LabelService::caseMath(5, 10)['cases']);
        $this->assertSame('1 case of 5 (less than a full case)', LabelService::caseMath(5, 10)['text']);
        $this->assertNull(LabelService::caseMath(null, 10)['cases']);
        $this->assertNull(LabelService::caseMath(320, 0)['cases']);
    }

    public function test_label_lines_for_320_and_315_at_10_per_case(): void
    {
        $svc = app(LabelService::class);
        $even = $svc->withCases($svc->clean($this->fields()));
        $this->assertSame(['Bodega Boyz Watermelon Zkittlez 0.7g', 'Shortiez Preroll', '', 'Batch # BBWZK261117', '320 Count', '32 Case', '10 Count per case box'], $svc->lines($even));
        $this->assertSame([], array_values(array_filter($svc->warnings($even, null, $svc->label($even)->layout()), fn ($w) => str_contains($w, 'divide'))));

        $odd = $svc->withCases($svc->clean($this->fields(['total' => '315'])));
        $this->assertSame(32, $odd['cases']);
        $this->assertSame('315 Count', $svc->lines($odd)[4]);
        $this->assertSame('32 Case (1 partial; 5 count)', $svc->lines($odd)[5], 'The partial case goes on the label itself');
        $this->assertContains('315 does not divide evenly by 10: 31 full cases + 1 case of 5.', $svc->warnings($odd, null, $svc->label($odd)->layout()));

        // Long case line still fits at 10.5 pt with the barcode.
        $big = $svc->withCases($svc->clean($this->fields(['total' => '8333'])));
        $this->assertSame('834 Case (1 partial; 3 count)', $svc->lines($big)[5]);
        $L = $svc->label($big)->layout();
        $this->assertSame(10.5, $L['size']);
        $this->assertFalse($L['overlaps_barcode']);
    }

    public function test_text_shrinks_to_fit_198pt_and_layout_matches_template(): void
    {
        $svc = app(LabelService::class);
        // A long product line wraps onto 2 lines first; only a line too long for 2 lines makes the text smaller.
        $w = $svc->withCases($svc->clean($this->fields(['title' => 'Bodega Boyz Watermelon Zkittlez Extra Long Strain Name 0.7g', 'type_text' => ''])));
        $WL = $svc->label($w)->layout();
        $this->assertSame(10.5, $WL['size']);
        $this->assertSame('product', $WL['text'][0]['role']);
        $this->assertSame('product', $WL['text'][1]['role']);
        $this->assertSame('Bodega Boyz Watermelon Zkittlez Extra Long Strain Name 0.7g', $WL['text'][0]['text'].' '.$WL['text'][1]['text']);
        $f = $svc->withCases($svc->clean($this->fields(['title' => 'Bodega Boyz Watermelon Zkittlez Extra Long Strain Name Supreme Reserve 0.7g Shortiez Preroll', 'type_text' => ''])));
        $L = $svc->label($f)->layout();
        $this->assertLessThan(10.5, $L['size']);
        $this->assertTrue($L['fits']);
        foreach ($L['text'] as $t) {
            $this->assertLessThanOrEqual(198.0, \App\Support\Labels\FontMetrics::width($t['text'], 'Helvetica-Bold', $L['size']));
            $this->assertSame(9.0, $t['x']);
        }
        $this->assertEqualsWithDelta(198.0, array_sum(array_map(fn ($b) => 0, $L['bars'])) + (end($L['bars'])[0] + end($L['bars'])[2] - $L['bars'][0][0]), 0.5);
        $this->assertSame(15.0, $L['bars'][0][1]);
        $this->assertSame(26.0, $L['bars'][0][3]);

        $nob = $svc->label(['barcode_on' => false] + $f)->layout();
        $this->assertSame([], $nob['bars']);
        $this->assertGreaterThan($L['size'], $nob['size'] + 0.01);
    }

    public function test_batch_picker_fills_the_form_from_the_batch(): void
    {
        $b = $this->startBatch(320);
        $b->update(['barcode_number' => '1A4120300000596000000999']);
        $this->actingAs($this->sae)->get('/labels')->assertOk()->assertSee('id="batchDrop"', false);
        $r = $this->actingAs($this->sae)->get('/labels/new?batch='.$b->batch_number)->assertOk();
        $r->assertSee('value="Bodega Boyz Watermelon Zkittlez 0.7g Shortiez Preroll"', false)->assertDontSee('id="f_type"', false)
            ->assertSee('value="'.$b->batch_number.'"', false)->assertSee('value="10"', false)->assertSee('value="Count per case box"', false)
            ->assertSee('1A4120300000596000000999')->assertSee('<svg', false);
        $d = app(LabelService::class)->defaults($b->fresh());
        $this->assertNull($d['total'], 'Sae types the total');
        $this->assertTrue($d['barcode_on']);

        // Brand that doesn't require a barcode: the batch's barcode is still shown and printed; brand wording for the last line.
        $this->brand->update(['requires_barcode' => false, 'label_per_case_text' => 'Count per case']);
        $d = app(LabelService::class)->defaults($b->fresh());
        $this->assertTrue($d['barcode_on']);
        $this->assertFalse($d['barcode_required']);
        $this->assertSame('1A4120300000596000000999', app(LabelService::class)->applyBarcode($d, $b->fresh())['barcode_value']);
        $this->assertSame('Count per case', $d['per_case_label']);
        $this->actingAs($this->sae)->get('/labels/new?batch='.$b->batch_number)->assertSee('1A4120300000596000000999')->assertDontSee('need one</span>', false);
    }

    public function test_live_preview_returns_svg_and_the_partial_warning(): void
    {
        $b = $this->startBatch(320);
        $b->update(['barcode_number' => '1A41203000005960000001707']);
        $j = $this->actingAs($this->sae)->postJson('/labels/preview?batch='.$b->batch_number, ['f' => $this->fields(['total' => '315', 'batch_number' => $b->batch_number]), 'copies' => 32])
            ->assertOk()->json();
        $this->assertStringContainsString('32 Case (1 partial; 5 count)', $j['svg']);
        $this->assertStringContainsString('315 does not divide evenly by 10: 31 full cases + 1 case of 5.', implode(' ', $j['warnings']));
        $this->assertSame([], $j['errors']);
        $this->assertSame(0, LabelPrint::count(), 'Preview is not a print');
        // The viewer can see the preview but not print.
        $this->actingAs($this->viewer)->postJson('/labels/preview?batch='.$b->batch_number, ['f' => $this->fields()])->assertOk();
        $this->actingAs($this->viewer)->post('/labels/print?batch='.$b->batch_number, ['f' => $this->fields(), 'copies' => 1])->assertForbidden();
    }

    public function test_print_makes_a_3x2_pdf_per_copy_and_logs_it(): void
    {
        $b = $this->startBatch(320);
        $b->update(['barcode_number' => '1A41203000005960000001707']);
        $res = $this->actingAs($this->sae)->post('/labels/print?batch='.$b->batch_number, ['f' => $this->fields(['batch_number' => $b->batch_number, 'total' => '315']), 'copies' => 3]);
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $res->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertSame(3, preg_match_all('#/Type /Page /Parent#', $pdf));
        $this->assertStringContainsString('/MediaBox [0 0 216 144]', $pdf);
        $this->assertStringContainsString('(32 Case \(1 partial; 5 count\)) Tj', $pdf);
        $this->assertStringContainsString('(1A41203000005960000001707) Tj', $pdf);

        $lp = LabelPrint::firstOrFail();
        $this->assertSame(3, $lp->quantity);
        $this->assertSame($b->id, $lp->batch_id);
        $this->assertSame($this->sae->id, $lp->printed_by);
        $this->assertSame(315, $lp->fields['total']);
        $this->assertSame(32, $lp->fields['cases']);
        $this->assertSame(hash('sha256', $pdf), $lp->pdf_sha256);
        $a = AuditLog::where('action', 'print')->where('record_type', 'label_print')->where('record_id', $lp->id)->firstOrFail();
        $this->assertSame($this->sae->id, $a->user_id);
        $this->assertSame(3, $a->details['quantity']);
        $this->assertSame($b->batch_number, $a->details['batch']);
        $this->assertSame('1A41203000005960000001707', $a->details['fields']['barcode_value']);
        $this->actingAs($this->sae)->get('/labels')->assertSee($b->batch_number)->assertSee('Print again');
        $this->actingAs($this->sae)->get('/labels/new?from='.$lp->id)->assertOk()->assertSee('value="315"', false);

        // The print log can't be changed or deleted.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('label_prints')->where('id', $lp->id)->update(['quantity' => 99]);
    }

    public function test_barcode_comes_from_the_batch_log_and_a_missing_one_blocks_printing(): void
    {
        $b = $this->startBatch(320);
        $url = '/labels/print?batch='.$b->batch_number;
        // Missing barcode number on a brand that requires one: blocked, with a link to the batch.
        $this->actingAs($this->sae)->get('/labels/new?batch='.$b->batch_number)->assertOk()
            ->assertSee('has no barcode number yet')->assertSee(route('batch-log.show', $b).'#barcode', false);
        $j = $this->actingAs($this->sae)->postJson('/labels/preview?batch='.$b->batch_number, ['f' => $this->fields(['batch_number' => $b->batch_number, 'barcode_value' => ''])])->json();
        $this->assertTrue($j['missing_barcode']);
        $this->assertStringContainsString("batch {$b->batch_number} has no barcode number yet. Type it in the Barcode box", implode(' ', $j['errors']));
        $this->actingAs($this->sae)->post($url, ['f' => $this->fields(['batch_number' => $b->batch_number, 'barcode_value' => '']), 'copies' => 1])
            ->assertSessionHasErrors('gate');
        $this->assertSame(0, LabelPrint::count());

        // A barcode typed on the label form is printed (for this print), even if the batch has none.
        $j = $this->actingAs($this->sae)->postJson('/labels/preview?batch='.$b->batch_number, ['f' => $this->fields(['batch_number' => $b->batch_number, 'barcode_value' => 'typed 01'])])->json();
        $this->assertFalse($j['missing_barcode']);
        $this->assertSame([], $j['errors']);
        $res = $this->actingAs($this->sae)->post($url, ['f' => $this->fields(['batch_number' => $b->batch_number, 'barcode_value' => 'TYPED01']), 'copies' => 1])->assertOk();
        $this->assertStringContainsString('(TYPED01) Tj', $res->getContent());
        $this->assertNull($b->fresh()->barcode_number, 'typing on the label does not change the Batch Log');

        // Empty box: the Batch Log value is used.
        $b->update(['barcode_number' => 'PKG-0001']);
        $res = $this->actingAs($this->sae)->post($url, ['f' => $this->fields(['barcode_on' => '0', 'barcode_value' => '']), 'copies' => 1])->assertOk();
        $this->assertStringContainsString('(PKG-0001) Tj', $res->getContent());
        $this->assertSame('PKG-0001', LabelPrint::latest('id')->firstOrFail()->fields['barcode_value']);
        $this->actingAs($this->sae)->post($url, ['f' => $this->fields(), 'copies' => 0])->assertSessionHasErrors('gate');

        // Brand that does not require a barcode: the batch's barcode is STILL printed (Master box).
        $this->brand->update(['requires_barcode' => false]);
        $this->actingAs($this->sae)->get('/labels/new?batch='.$b->batch_number)->assertOk()->assertSee('value="PKG-0001"', false)->assertDontSee('has no barcode number yet');
        $res = $this->actingAs($this->sae)->post($url, ['f' => $this->fields(['barcode_on' => '0', 'barcode_value' => '']), 'copies' => 1])->assertOk();
        $this->assertStringContainsString('(PKG-0001) Tj', $res->getContent());
        $this->assertTrue(LabelPrint::latest('id')->first()->fields['barcode_on']);
        // No barcode anywhere and not required: prints without one.
        $b->update(['barcode_number' => null]);
        $res = $this->actingAs($this->sae)->post($url, ['f' => $this->fields(['barcode_value' => '']), 'copies' => 1])->assertOk();
        $this->assertStringNotContainsString('PKG-0001', $res->getContent());
        $this->assertFalse(LabelPrint::latest('id')->first()->fields['barcode_on']);
    }

    public function test_batch_log_barcode_number_is_editable_by_admin_and_supervisor_and_audited(): void
    {
        $b = $this->startBatch(320);
        $this->actingAs($this->op)->post("/batch-log/{$b->batch_number}/barcode", ['barcode_number' => 'X1'])->assertForbidden();
        $this->actingAs($this->viewer)->post("/batch-log/{$b->batch_number}/barcode", ['barcode_number' => 'X1'])->assertForbidden();
        $this->actingAs($this->leo)->post("/batch-log/{$b->batch_number}/barcode", ['barcode_number' => 'bad value é'])->assertSessionHasErrors('barcode_number');
        $this->actingAs($this->leo)->post("/batch-log/{$b->batch_number}/barcode", ['barcode_number' => ' 1a4120300000596000001707 '])->assertSessionHasNoErrors();
        $this->assertSame('1A4120300000596000001707', $b->fresh()->barcode_number);
        $this->actingAs($this->andy)->post("/batch-log/{$b->batch_number}/barcode", ['barcode_number' => 'PKG-2'])->assertSessionHasNoErrors();
        $log = AuditLog::where('record_type', 'batch')->where('record_id', $b->id)->where('action', 'update')->latest('id')->firstOrFail();
        $this->assertSame(['barcode_number' => '1A4120300000596000001707'], $log->details['old']);
        $this->assertSame(['barcode_number' => 'PKG-2'], $log->details['new']);
        $this->assertSame($this->andy->id, $log->user_id);
        $this->actingAs($this->op)->get('/batch-log?status=all')->assertOk()->assertSee('Barcode number')->assertSee('PKG-2');
        $this->actingAs($this->op)->get("/batch-log/{$b->batch_number}")->assertOk()->assertSee('PKG-2')->assertDontSee('Save barcode number');
        $this->actingAs($this->leo)->get("/batch-log/{$b->batch_number}")->assertSee('Save barcode number');
        $this->actingAs($this->andy)->post("/batch-log/{$b->batch_number}/barcode", ['barcode_number' => ''])->assertSessionHasNoErrors();
        $this->assertNull($b->fresh()->barcode_number);
    }

    public function test_setup_has_count_per_case_default_10_and_brand_label_settings(): void
    {
        $p = Product::create(['code' => 'x-1', 'name' => 'New product', 'brand_id' => $this->brand->id]);
        $this->assertSame(10, $p->fresh()->case_pack_qty);
        $this->actingAs($this->andy)->get("/setup/products/{$this->product->id}")->assertOk()->assertSee('Count per case (case labels)');
        $this->actingAs($this->andy)->get("/setup/brands/{$this->brand->id}")->assertOk()->assertSee('Case labels need a barcode')->assertSee('Count per case box');
        $this->assertSame('case-3x2-v1', CaseLabel::cfg('template'));
    }
}
