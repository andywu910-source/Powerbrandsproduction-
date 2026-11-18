<?php

namespace Tests\Feature\Phase1;

use App\Models\AuditLog;
use App\Models\LabelPrint;
use App\Models\LabelTemplate;
use App\Services\LabelService;
use App\Support\Labels\CaseLabel;
use App\Support\Labels\FontMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

/** Product line (Brand + strain + size + type, wraps to 2 lines) and the on-label editor. */
class LabelEditorTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private const BC = '1A41203000005960000001707';
    private const LINE = 'Bodega Boyz Watermelon Zkittlez 0.7g Shortiez Preroll';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,labels']);
        $this->brand->update(['name' => 'Bodega Boyz', 'requires_barcode' => true]);
        $this->product->update(['name' => 'Watermelon Zkittlez', 'unit_net_qty' => 0.7, 'unit_net_uom' => 'g', 'label_type_text' => 'Shortiez Preroll']);
    }

    private function fields(array $over = []): array
    {
        return $over + ['label_type' => 'master', 'title' => self::LINE, 'type_text' => '', 'batch_label' => 'Batch #', 'batch_number' => 'BBWZK261117',
            'total' => '320', 'count_label' => 'Count', 'cases' => '', 'case_label' => 'Case', 'per_case' => '10', 'per_case_label' => 'Count per case box',
            'barcode_on' => '1', 'barcode_value' => self::BC];
    }

    private function batch()
    {
        $b = $this->startBatch(320);
        $b->update(['barcode_number' => self::BC]);

        return $b->fresh();
    }

    public function test_product_line_is_brand_strain_size_and_type(): void
    {
        $p = $this->product->fresh('brand');
        $this->assertSame(self::LINE, LabelService::productLine($p));
        $p->update(['name' => 'Watermelon Zkittlez 0.7g']);
        $this->assertSame(self::LINE, LabelService::productLine($p->fresh('brand')), 'size already in the name is not repeated');
        $p->update(['name' => 'Bodega Boyz Watermelon Zkittlez', 'unit_net_qty' => 1, 'label_type_text' => null, 'product_type' => 'Shortiez']);
        $this->assertSame('Bodega Boyz Watermelon Zkittlez 1g Shortiez Preroll', LabelService::productLine($p->fresh('brand')), 'brand not doubled; type from product type + family');

        $d = app(LabelService::class)->defaults($this->batch()->fresh('product.brand'));
        $this->assertSame('', $d['type_text']);
        $this->assertSame(['Bodega Boyz Watermelon Zkittlez 1g Shortiez Preroll', ''], array_slice(app(LabelService::class)->lines($d), 0, 2), 'no separate type line');
    }

    public function test_product_line_wraps_to_two_lines_before_shrinking_on_both_label_types(): void
    {
        $svc = app(LabelService::class);
        foreach (['master' => 10.5, 'case' => 12.0] as $type => $base) {
            foreach ([true, false] as $bar) {
                $long = self::LINE;   // 273 pt at 10.5 pt: too long for one 198 pt line
                $f = $svc->withCases($svc->clean($this->fields(['label_type' => $type, 'title' => $long, 'barcode_on' => $bar ? '1' : ''])));
                $L = $svc->label($f)->layout();
                $prod = array_values(array_filter($L['text'], fn ($t) => $t['role'] === 'product'));
                $this->assertCount(2, $prod, "$type wraps");
                $this->assertSame($long, $prod[0]['text'].' '.$prod[1]['text']);
                $this->assertTrue($L['fits']);
                $this->assertFalse($L['overlaps_barcode']);
                if ($bar) {
                    $this->assertSame($base, $L['size'], "$type keeps its size when 2 lines are enough");
                }
                foreach ($L['text'] as $t) {
                    $this->assertLessThanOrEqual(198.0, FontMetrics::width($t['text'], 'Helvetica-Bold', $t['size']));
                }
            }
        }
        // A short product line stays on one line.
        $one = $svc->label($svc->withCases($svc->clean($this->fields(['title' => 'Bodega Boyz Gelato 1g Preroll']))))->layout();
        $this->assertCount(1, array_filter($one['text'], fn ($t) => $t['role'] === 'product'));
    }

    public function test_preview_svg_and_pdf_use_identical_positions(): void
    {
        $svc = app(LabelService::class);
        $f = $svc->withCases($svc->clean($this->fields()));
        $auto = $svc->label($f)->toLayout();
        $auto['lines'][0]['x'] = 20.5; $auto['lines'][0]['y'] = 120; $auto['lines'][0]['size'] = 13;
        $auto['lines'][] = ['role' => 'custom', 'text' => 'KEEP DRY', 'x' => 150, 'y' => 60, 'size' => 8, 'edited' => true];
        $auto['barcode']['x'] = 12;
        foreach ([$svc->label($f), $svc->label($f, $auto)] as $label) {
            $L = $label->layout();
            $svg = $label->svg();
            $pdf = $label->pdf(1);
            preg_match_all('#<text data-line="\d+" x="([\d.]+)" y="([\d.]+)" font-size="([\d.]+)"[^>]*>([^<]*)</text>#', $svg, $sm, PREG_SET_ORDER);
            preg_match_all('#/F1 ([\d.]+) Tf\n|1 0 0 1 ([\d.]+) ([\d.]+) Tm \((.*?)\) Tj#', $pdf, $pm, PREG_SET_ORDER);
            $pdfLines = [];
            $size = null;
            foreach ($pm as $m) {
                if (($m[1] ?? '') !== '') { $size = (float) $m[1]; continue; }
                $pdfLines[] = [(float) $m[2], (float) $m[3], $size, $m[4]];
            }
            $svgLines = array_map(fn ($m) => [(float) $m[1], round(144 - (float) $m[2], 3), (float) $m[3], html_entity_decode($m[4])], $sm);
            $this->assertNotEmpty($svgLines);
            $this->assertCount(count($svgLines), array_slice($pdfLines, 0, count($svgLines)));
            foreach ($svgLines as $i => $s) {
                $p = $pdfLines[$i];
                $this->assertEqualsWithDelta($s[0], $p[0], 0.001, 'x');
                $this->assertEqualsWithDelta($s[1], $p[1], 0.001, 'y');
                $this->assertSame($s[2], $p[2], 'size');
                $this->assertSame($s[3], $p[3]);
            }
            // Barcode number: same x and y in both.
            $bc = $L['barcode'];
            $this->assertStringContainsString('<text x="'.$bc['x'].'" y="'.round(144 - $bc['y'], 3).'"', $svg);
            $this->assertMatchesRegularExpression('#/F2 [\d.]+ Tf 1 0 0 1 '.preg_quote(rtrim(rtrim(sprintf('%.3F', $bc['x']), '0'), '.'), '#').' #', $pdf);
            // First bar at the same place.
            [$x, $y, $w, $h] = $L['bars'][0];
            $this->assertStringContainsString('x="'.$x.'" y="'.round(144 - $y - $h, 3).'"', $svg);
        }
        $this->assertStringContainsString('KEEP DRY', $svgLines[array_key_last($svgLines)][3]);
    }

    public function test_edited_layout_previews_prints_and_is_stored_in_the_print_log(): void
    {
        $b = $this->batch();
        $src = '?batch_id='.$b->id;
        $f = $this->fields(['batch_number' => $b->batch_number]);
        $j = $this->actingAs($this->sae)->postJson('/labels/preview'.$src, ['f' => $f])->assertOk()->json();
        $this->assertFalse($j['edited']);
        $layout = $j['layout'];
        $this->assertSame('product', $layout['lines'][0]['role']);
        // Edit: move the product line, bigger font, change the count line's text, add a line with a break, blank space.
        $layout['lines'][0] = ['x' => 12, 'y' => 128, 'size' => 11] + $layout['lines'][0];
        $layout['lines'][0]['x'] = 12; $layout['lines'][0]['y'] = 128; $layout['lines'][0]['size'] = 11;
        $ti = collect($layout['lines'])->search(fn ($l) => $l['role'] === 'total');
        $layout['lines'][$ti]['text'] = '320 pcs'; $layout['lines'][$ti]['edited'] = true;
        $layout['lines'][] = ['role' => 'custom', 'text' => "KEEP COOL\nNO RESALE", 'x' => 140, 'y' => 75, 'size' => 7, 'edited' => true];
        $j2 = $this->actingAs($this->sae)->postJson('/labels/preview'.$src, ['f' => $f, 'layout' => json_encode($layout)])->assertOk()->json();
        $this->assertTrue($j2['edited']);
        $this->assertSame([], $j2['errors']);
        $this->assertStringContainsString('320 pcs', $j2['svg']);
        $this->assertStringContainsString('font-size="11"', $j2['svg']);
        $texts = array_column($j2['layout']['lines'], 'text');
        $this->assertContains('KEEP COOL', $texts);
        $this->assertContains('NO RESALE', $texts, 'a typed line break makes a new line');
        $k = array_search('NO RESALE', $texts, true);
        $this->assertEqualsWithDelta(75 - 7 * 1.135, $j2['layout']['lines'][$k]['y'], 0.02);

        $res = $this->actingAs($this->sae)->post('/labels/print'.$src, ['f' => $f, 'copies' => 2, 'layout' => json_encode($j2['layout'])])->assertOk();
        $pdf = $res->getContent();
        $this->assertStringContainsString('1 0 0 1 12 128 Tm', $pdf);
        $this->assertStringContainsString('(320 pcs) Tj', $pdf);
        $this->assertStringContainsString('(NO RESALE) Tj', $pdf);
        $lp = LabelPrint::latest('id')->firstOrFail();
        $this->assertTrue($lp->layout_edited);
        $this->assertSame($j2['layout']['lines'], $lp->layout['lines'], 'the print log stores the exact layout printed');
        $this->assertNotNull($lp->layout['barcode']);
        $audit = AuditLog::where('action', 'print')->latest('id')->firstOrFail();
        $this->assertTrue((bool) ($audit->details['layout_edited'] ?? false));

        // A standard print stores its layout too (not edited).
        $this->actingAs($this->sae)->post('/labels/print'.$src, ['f' => $f, 'copies' => 1])->assertOk();
        $std = LabelPrint::latest('id')->firstOrFail();
        $this->assertFalse($std->layout_edited);
        $this->assertSame(self::LINE, $std->layout['lines'][0]['text'].' '.$std->layout['lines'][1]['text'], 'product line wrapped onto 2 lines');

        // Reprint from the edited job: the form starts from that job's layout.
        $this->actingAs($this->sae)->get('/labels/new?from='.$lp->id)->assertOk()
            ->assertSee('Edited layout (from that print)')->assertSee('320 pcs')->assertSee('id="layoutInput" value="{', false);
        $this->actingAs($this->sae)->get('/labels/new?from='.$std->id)->assertOk()->assertSee('Standard layout')->assertSee('id="layoutInput" value=""', false)
            ->assertSee('Edit on label')->assertSee('Reset to default')->assertDontSee('Save as template for this product');
    }

    public function test_unedited_data_lines_follow_the_current_fields(): void
    {
        $svc = app(LabelService::class);
        $f = $svc->withCases($svc->clean($this->fields()));
        $layout = $svc->label($f)->toLayout();
        foreach ($layout['lines'] as &$l) {
            $l['y'] -= 2;   // moved, text not edited
        }
        unset($l);
        $g = $svc->withCases($svc->clean($this->fields(['total' => '315', 'batch_number' => 'NEW123'])));
        $L = $svc->label($g, $layout)->layout();
        $texts = array_column($L['text'], 'text');
        $this->assertContains('315 Count', $texts);
        $this->assertContains('Batch # NEW123', $texts);
        $this->assertContains('32 Case (1 partial; 5 count)', $texts);
        $this->assertNotContains('320 Count', $texts);
        $this->assertEqualsWithDelta($layout['lines'][0]['y'], $L['text'][0]['y'], 0.001, 'position kept');
    }

    public function test_barcode_can_move_but_not_shrink_below_its_minimum_width(): void
    {
        $svc = app(LabelService::class);
        $f = $svc->withCases($svc->clean($this->fields()));
        $layout = $svc->label($f)->toLayout();
        $min = CaseLabel::minBarcodeWidth(self::BC);
        $this->assertSame($min, $layout['barcode_min_w']);
        $this->assertGreaterThan(100, $min);
        $this->assertLessThanOrEqual(198, $min);

        $layout['barcode'] = ['x' => 4, 'y' => 30, 'w' => 40, 'h' => 5];
        $L = $svc->label($f, $layout)->layout();
        $this->assertEqualsWithDelta($min, $L['barcode_box']['w'], 0.01, 'clamped to the minimum width');
        $this->assertSame(15.0, (float) $L['barcode_box']['h'], 'height clamped');
        $this->assertSame(4.0, (float) $L['barcode_box']['x'], 'moved');
        $this->assertGreaterThanOrEqual((float) config('pbp_labels.barcode.min_module'), $L['barcode']['module']);
        $first = $L['bars'][0]; $last = end($L['bars']);
        $this->assertEqualsWithDelta($min, $last[0] + $last[2] - $first[0], 0.6);

        // Dragged off the page: kept on the page.
        $layout['barcode'] = ['x' => 900, 'y' => -50, 'w' => 500, 'h' => 26];
        $box = $svc->label($f, $layout)->layout()['barcode_box'];
        $this->assertLessThanOrEqual(216.0, $box['x'] + $box['w']);
        $this->assertGreaterThan(0, $box['y']);

        // Text dragged onto the barcode: preview error and the print is refused.
        $b = $this->batch();
        $ff = $this->fields(['batch_number' => $b->batch_number]);
        $layout = $svc->label($svc->withCases($svc->clean($ff)))->toLayout();
        $layout['lines'][0]['y'] = $layout['barcode']['y'] + 5;
        $layout['lines'][0]['x'] = $layout['barcode']['x'] + 10;
        $j = $this->actingAs($this->sae)->postJson('/labels/preview?batch_id='.$b->id, ['f' => $ff, 'layout' => json_encode($layout)])->json();
        $this->assertContains('A line overlaps the barcode. Move it so the barcode stays scannable.', $j['errors']);
        $n = LabelPrint::count();
        $this->actingAs($this->sae)->post('/labels/print?batch_id='.$b->id, ['f' => $ff, 'copies' => 1, 'layout' => json_encode($layout)])->assertSessionHasErrors('gate');
        $this->assertSame($n, LabelPrint::count());
    }

    public function test_save_as_template_is_admin_or_supervisor_only_and_is_used_next_time(): void
    {
        $b = $this->batch();
        $svc = app(LabelService::class);
        $f = $this->fields(['batch_number' => $b->batch_number]);
        $layout = $svc->label($svc->withCases($svc->clean($f)))->toLayout();
        $layout['lines'][0]['size'] = 12;
        $layout['lines'][] = ['role' => 'custom', 'text' => 'KEEP DRY', 'x' => 150, 'y' => 70, 'size' => 8, 'edited' => true];
        $body = ['layout' => json_encode($layout), 'label_type' => 'master'];

        $this->actingAs($this->sae)->postJson('/labels/template?batch_id='.$b->id, $body)->assertForbidden();
        $this->actingAs($this->viewer)->postJson('/labels/template?batch_id='.$b->id, $body)->assertForbidden();
        $this->assertSame(0, LabelTemplate::count());
        $this->actingAs($this->sae)->get('/labels/new?batch_id='.$b->id)->assertOk()->assertDontSee('Save as template for this product');
        $this->actingAs($this->leo)->get('/labels/new?batch_id='.$b->id)->assertOk()->assertSee('Save as template for this product');

        $this->actingAs($this->leo)->postJson('/labels/template?batch_id='.$b->id, $body)->assertOk()->assertJson(['ok' => true]);
        $t = LabelTemplate::firstOrFail();
        $this->assertSame([$this->product->id, 'master', $this->leo->id], [$t->product_id, $t->label_type, $t->saved_by]);
        $roles = collect($t->layout['lines'])->keyBy('role');
        $this->assertSame('', $roles['batch']['text'], 'data lines keep only place and size');
        $this->assertSame('KEEP DRY', $roles['custom']['text']);
        $this->assertTrue(AuditLog::where('action', 'label_template_saved')->exists());

        // Next label for this product starts from the template; batch number and counts still fill in.
        $r = $this->actingAs($this->sae)->get('/labels/new?batch_id='.$b->id)->assertOk();
        $r->assertSee('Product template')->assertSee('KEEP DRY')->assertSee('font-size="12"', false)->assertSee('Batch # '.$b->batch_number);
        // Case box has no template: standard layout.
        $this->actingAs($this->sae)->get('/labels/new?type=case&batch_id='.$b->id)->assertOk()->assertSee('Standard layout')->assertDontSee('KEEP DRY');
        $j = $this->actingAs($this->sae)->postJson('/labels/preview?batch_id='.$b->id, ['f' => $f, 'use_template' => '1'])->json();
        $this->assertTrue($j['edited']);
        $this->assertStringContainsString('KEEP DRY', $j['svg']);

        // Append-only.
        $this->expectException(\Throwable::class);
        $t->delete();
    }

    public function test_partial_case_label_uses_the_edited_layout(): void
    {
        $b = $this->batch();
        $svc = app(LabelService::class);
        $f = $this->fields(['label_type' => 'case', 'batch_number' => $b->batch_number, 'total' => '315']);
        $layout = $svc->label($svc->withCases($svc->clean($f)))->toLayout();
        $pi = collect($layout['lines'])->search(fn ($l) => $l['role'] === 'per_case');
        $layout['lines'][$pi]['x'] = 30;
        $j = $this->actingAs($this->sae)->postJson('/labels/preview?batch_id='.$b->id, ['f' => $f, 'layout' => json_encode($layout)])->json();
        $this->assertStringContainsString('x="30" y="', $j['svg']);
        $this->assertMatchesRegularExpression('#x="30" y="[\d.]+" font-size="[\d.]+" xml:space="preserve">5 Count per case box#', $j['partial_svg']);
        $pdf = $this->actingAs($this->sae)->post('/labels/print?batch_id='.$b->id, ['f' => $f, 'which' => 'partial', 'partial_copies' => 1, 'layout' => json_encode($layout)])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#1 0 0 1 30 [\d.]+ Tm \(5 Count per case box\) Tj#', $pdf);
        $lp = LabelPrint::latest('id')->firstOrFail();
        $this->assertTrue($lp->partial);
        $this->assertTrue($lp->layout_edited);
    }

    public function test_release_check_finds_the_editor_and_new_columns(): void
    {
        $this->batch();
        $code = \Illuminate\Support\Facades\Artisan::call('pbp:release-check', ['--batch-pick' => true, '--editor' => true,
            '--column' => ['label_prints.layout', 'label_prints.layout_edited', 'label_templates.layout']]);
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('OK    the label form has the on-label editor', $out);
        $this->assertStringContainsString("OK    product line is '".self::LINE."'", $out);
    }
}
