<?php

namespace Tests\Feature\Phase1;

use App\Models\AuditLog;
use App\Models\LabelPrint;
use App\Services\LabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

/** Labels: Master box (the whole count, default) and Case box (one small box inside it). */
class CaseBoxLabelsTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private const BC = '1A41203000005960000001707';

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
        return $over + ['label_type' => 'case', 'title' => 'Bodega Boyz Watermelon Zkittlez 0.7g', 'type_text' => 'Shortiez Preroll', 'batch_label' => 'Batch #',
            'batch_number' => 'BBWZK261117', 'total' => '', 'count_label' => 'Count', 'cases' => '', 'case_label' => 'Case', 'per_case' => '10',
            'per_case_label' => 'Count per case box', 'barcode_on' => '1', 'barcode_value' => self::BC];
    }

    public function test_master_box_is_the_default_and_unchanged(): void
    {
        $svc = app(LabelService::class);
        $b = $this->startBatch(320);
        $this->assertSame('master', $svc->defaults($b)['label_type']);
        $this->assertSame('master', $svc->clean(['label_type' => 'nonsense'])['label_type']);
        $m = $svc->withCases($svc->clean($this->fields(['label_type' => 'master', 'total' => '315'])));
        $this->assertSame(['Bodega Boyz Watermelon Zkittlez 0.7g', 'Shortiez Preroll', '', 'Batch # BBWZK261117', '315 Count', '32 Case (1 partial; 5 count)', '10 Count per case box'], $svc->lines($m));
        $this->assertSame('case-3x2-v1', LabelService::template($m));
        $this->actingAs($this->sae)->get('/labels/new?batch='.$b->batch_number)->assertOk()
            ->assertSee('Label type')->assertSee('Master box')->assertSee('Case box')->assertSee('value="master" checked', false);
    }

    public function test_case_box_lines_with_and_without_barcode(): void
    {
        $svc = app(LabelService::class);
        $f = $svc->withCases($svc->clean($this->fields()));
        $this->assertSame(['Bodega Boyz Watermelon Zkittlez 0.7g', '', 'Batch # BBWZK261117', '10 Count per case box'], $svc->lines($f));
        $this->assertNull($f['cases'], 'Case box never fills the master-box case count');
        $this->assertSame([], $svc->errors($f, 1), 'Count per case on its own is enough');
        $this->assertSame([], array_values(array_filter($svc->warnings($f, null, $svc->label($f)->layout()), fn ($w) => str_contains($w, 'total'))));
        $L = $svc->label($f)->layout();
        $this->assertLessThanOrEqual(12.0, $L['size']);   // shrinks only if the longest line needs it
        $this->assertTrue($L['fits']);
        $this->assertSame(12.0, $svc->label(['title' => 'Bodega Boyz Gelato 1g'] + $f)->layout()['size']);
        $this->assertNotEmpty($L['bars']);
        $this->assertFalse($L['overlaps_barcode']);
        foreach ($L['text'] as $t) {
            $this->assertSame(9.0, $t['x'], 'left-aligned');
        }
        $this->assertSame('Helvetica-Bold', $L['font']);
        $this->assertSame('casebox-3x2-v1', LabelService::template($f));

        $nob = $svc->label(['barcode_on' => false] + $f)->layout();
        $this->assertSame([], $nob['bars']);
        $this->assertSame(14.0, $svc->label(['barcode_on' => false, 'title' => 'Bodega Boyz Gelato 1g'] + $f)->layout()['size']);

        $this->assertContains('Type the count per case box.', $svc->errors($svc->clean($this->fields(['per_case' => ''])), 1));
    }

    public function test_quantity_is_full_cases_and_partial_gets_its_own_label(): void
    {
        $svc = app(LabelService::class);
        $this->assertSame(['full' => 31, 'remainder' => 5], LabelService::caseBoxes($svc->clean($this->fields(['total' => '315']))));
        $this->assertSame(['full' => 32, 'remainder' => 0], LabelService::caseBoxes($svc->clean($this->fields(['total' => '320']))));
        $this->assertSame(['full' => 0, 'remainder' => 5], LabelService::caseBoxes($svc->clean($this->fields(['total' => '5']))));
        $this->assertSame(['full' => null, 'remainder' => null], LabelService::caseBoxes($svc->clean($this->fields())));

        $odd = $svc->clean($this->fields(['total' => '315']));
        $this->assertContains('315 ÷ 10 = 31 full case boxes + 1 partial case box of 5: print the partial case label too.', $svc->warnings($odd, null, $svc->label($odd)->layout()));
        $partial = $svc->clean(['partial' => true] + $this->fields(['total' => '315']));
        $this->assertSame('5 Count per case box', $svc->lines($partial)[3]);
        $this->assertContains('320 divides evenly by 10: there is no partial case box.', $svc->errors($svc->clean(['partial' => true] + $this->fields(['total' => '320'])), 1));
        $this->assertContains('Type the total count to print the partial case label.', $svc->errors($svc->clean(['partial' => true] + $this->fields()), 1));

        // The form defaults: 31 full case box labels + the partial row (quantity 1), both previews.
        $b = $this->startBatch(320);
        $b->update(['barcode_number' => self::BC]);
        $lp = LabelPrint::create(['public_id' => 'LBL-T1', 'batch_id' => $b->id, 'template' => 'casebox-3x2-v1', 'label_type' => 'case', 'quantity' => 31,
            'fields' => $this->fields(['batch_number' => $b->batch_number, 'total' => '315']), 'pdf_sha256' => str_repeat('0', 64), 'printed_by' => $this->sae->id]);
        $r = $this->actingAs($this->sae)->get('/labels/new?from='.$lp->id)->assertOk();
        $r->assertSee('value="case" checked', false)->assertSee('id="copiesCase" class="pb-input" type="number" min="1" max="1000" step="1" inputmode="numeric" name="copies" value="31"', false)
            ->assertSee('Partial case label')->assertSee('5 Count per case box')->assertSee('name="partial_copies" value="1"', false);
        $this->actingAs($this->sae)->get('/labels/new?type=case&batch='.$b->batch_number)->assertOk()->assertSee('value="case" checked', false);

        // Live preview switches with the type and returns the partial label too.
        $j = $this->actingAs($this->sae)->postJson('/labels/preview?batch='.$b->batch_number, ['f' => $this->fields(['batch_number' => $b->batch_number, 'total' => '315'])])->assertOk()->json();
        $this->assertSame('case', $j['label_type']);
        $this->assertSame(31, $j['full']);
        $this->assertSame(5, $j['remainder']);
        $this->assertStringContainsString('10 Count per case box', $j['svg']);
        $this->assertStringNotContainsString('Shortiez Preroll', $j['svg']);
        $this->assertStringContainsString('5 Count per case box', $j['partial_svg']);
        $m = $this->actingAs($this->sae)->postJson('/labels/preview?batch='.$b->batch_number, ['f' => $this->fields(['label_type' => 'master', 'batch_number' => $b->batch_number, 'total' => '315'])])->json();
        $this->assertStringContainsString('32 Case (1 partial; 5 count)', $m['svg']);
        $this->assertNull($m['partial_svg']);
    }

    public function test_printing_case_box_and_partial_logs_the_label_type(): void
    {
        $b = $this->startBatch(320);
        $b->update(['barcode_number' => self::BC]);
        $url = '/labels/print?batch='.$b->batch_number;
        $f = $this->fields(['batch_number' => $b->batch_number, 'total' => '315']);

        $res = $this->actingAs($this->sae)->post($url, ['f' => $f, 'copies' => 31, 'partial_copies' => 1, 'which' => 'full'])->assertOk();
        $pdf = $res->getContent();
        $this->assertSame(31, preg_match_all('#/Type /Page /Parent#', $pdf));
        $this->assertStringContainsString('(10 Count per case box) Tj', $pdf);
        $this->assertStringContainsString('(Batch # '.$b->batch_number.') Tj', $pdf);
        $this->assertStringContainsString('('.self::BC.') Tj', $pdf);
        $this->assertStringNotContainsString('Shortiez Preroll', $pdf);
        $this->assertStringContainsString('CaseBox_', $res->headers->get('Content-Disposition'));
        $lp = LabelPrint::latest('id')->firstOrFail();
        $this->assertSame(['case', false, 31, 'casebox-3x2-v1'], [$lp->label_type, $lp->partial, $lp->quantity, $lp->template]);

        $res = $this->actingAs($this->sae)->post($url, ['f' => $f, 'copies' => 31, 'partial_copies' => 1, 'which' => 'partial'])->assertOk();
        $this->assertSame(1, preg_match_all('#/Type /Page /Parent#', $res->getContent()));
        $this->assertStringContainsString('(5 Count per case box) Tj', $res->getContent());
        $lp = LabelPrint::latest('id')->firstOrFail();
        $this->assertSame(['case', true, 1], [$lp->label_type, $lp->partial, $lp->quantity]);
        $a = AuditLog::where('action', 'print')->where('record_id', $lp->id)->firstOrFail();
        $this->assertSame('case', $a->details['label_type']);
        $this->assertTrue($a->details['partial']);

        // Sae can change the quantity.
        $this->actingAs($this->sae)->post($url, ['f' => $f, 'copies' => 4, 'which' => 'full'])->assertOk();
        $this->assertSame(4, LabelPrint::latest('id')->first()->quantity);

        // Master box print is logged as master.
        $this->actingAs($this->sae)->post($url, ['f' => ['label_type' => 'master'] + $f, 'copies' => 32, 'partial_copies' => 1, 'which' => 'partial'])->assertOk();
        $lp = LabelPrint::latest('id')->first();
        $this->assertSame(['master', false, 32], [$lp->label_type, $lp->partial, $lp->quantity]);

        $this->actingAs($this->sae)->get('/labels')->assertSee('Case box (partial)')->assertSee('Master box');
    }

    public function test_case_box_needs_the_barcode_when_the_brand_requires_one(): void
    {
        $b = $this->startBatch(320);
        $url = '/labels/print?batch='.$b->batch_number;
        $this->actingAs($this->sae)->get('/labels/new?type=case&batch='.$b->batch_number)->assertSee('has no barcode number yet');
        $this->actingAs($this->sae)->post($url, ['f' => $this->fields(['batch_number' => $b->batch_number, 'barcode_value' => '']), 'copies' => 1, 'which' => 'full'])->assertSessionHasErrors('gate');
        $this->assertSame(0, LabelPrint::count());

        // Brand that does NOT require a barcode: the batch's barcode number is still printed.
        $this->brand->update(['requires_barcode' => false]);
        $b->update(['barcode_number' => self::BC]);
        $res = $this->actingAs($this->sae)->post($url, ['f' => $this->fields(['batch_number' => $b->batch_number, 'barcode_value' => '']), 'copies' => 2, 'which' => 'full'])->assertOk();
        $this->assertStringContainsString('('.self::BC.') Tj', $res->getContent());
        $this->assertStringContainsString('(10 Count per case box) Tj', $res->getContent());
        // ...and with no barcode anywhere it prints without one (not required).
        $b->update(['barcode_number' => null]);
        $res = $this->actingAs($this->sae)->post($url, ['f' => $this->fields(['batch_number' => $b->batch_number, 'barcode_value' => '']), 'copies' => 1, 'which' => 'full'])->assertOk();
        $this->assertStringNotContainsString(self::BC, $res->getContent());
    }
}
