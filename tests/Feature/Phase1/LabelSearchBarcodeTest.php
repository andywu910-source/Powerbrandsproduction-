<?php

namespace Tests\Feature\Phase1;

use App\Models\Batch;
use App\Models\LabelPrint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

/** Live report (Nov 17): partial product names found nothing; barcodes missing on labels. */
class LabelSearchBarcodeTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private const BC = '1A4120300000596000001707';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,labels']);
        $this->brand->update(['name' => 'Bodega Boyz', 'requires_barcode' => false]);
        $this->product->update(['name' => 'Watermelon Zkittlez 0.7g', 'product_type' => 'Shortiez', 'label_type_text' => 'Shortiez Preroll']);
    }

    private function search(string $q): array
    {
        return $this->actingAs($this->sae)->getJson('/labels/batches?q='.rawurlencode($q))->assertOk()->json();
    }

    public function test_empty_batch_log_says_so(): void
    {
        $j = $this->search('water');
        $this->assertSame([], $j['items']);
        $this->assertSame(0, $j['total_batches']);
        $this->assertSame('The Batch Log has no batches yet', $j['message']);
        $this->actingAs($this->sae)->get('/labels')->assertOk()->assertSee('The Batch Log has no batches yet')->assertSee('No batch yet? Start from a product');
    }

    public function test_search_matches_partial_words_case_insensitively(): void
    {
        $b = $this->startBatch(320);
        $b->update(['barcode_number' => self::BC]);
        foreach (['wat', 'WATER', 'melon', 'zkit', 'Zkittlez 0.7', 'bodega', 'BOYZ', 'shortiez', 'preroll', 'bodega melon', substr($b->batch_number, 0, 4), strtolower($b->batch_number), '596000001707', '1a41203'] as $q) {
            $j = $this->search($q);
            $this->assertSame([$b->id], array_column($j['items'], 'id'), "'$q' finds the batch");
            $this->assertNull($j['message']);
        }
        $j = $this->search('gelato');
        $this->assertSame([], $j['items']);
        $this->assertSame('No batch matches "gelato"', $j['message']);
        $this->assertSame(1, $j['total_batches']);
        $this->assertSame(self::BC, $this->search('water')['items'][0]['barcode']);
        // Void batches are never offered.
        $b->update(['status' => 'void']);
        $this->assertSame([], $this->search('water')['items']);
    }

    public function test_picker_is_a_live_dropdown(): void
    {
        $this->startBatch(320);
        $html = $this->actingAs($this->sae)->get('/labels')->assertOk()->getContent();
        $this->assertStringContainsString('id="batchDrop" role="listbox"', $html);
        $this->assertStringContainsString("setTimeout(search, 200)", $html, 'debounced as you type');
        $this->assertStringContainsString('q.length < 2', $html, 'opens from 2 characters');
        $this->assertStringNotContainsString('<datalist', $html);
    }

    public function test_barcode_always_printed_when_present_on_master_and_case_box(): void
    {
        $b = $this->startBatch(320);
        $b->update(['barcode_number' => self::BC]);
        $f = ['title' => 'Bodega Boyz Watermelon Zkittlez 0.7g Shortiez Preroll', 'batch_label' => 'Batch #', 'batch_number' => $b->batch_number, 'total' => '320',
            'count_label' => 'Count', 'case_label' => 'Case', 'per_case' => '10', 'per_case_label' => 'Count per case box'];
        foreach (['master', 'case'] as $type) {
            // requires_barcode is OFF for this brand, and the form sends no barcode: the batch's is used.
            $res = $this->actingAs($this->sae)->post('/labels/print?batch_id='.$b->id, ['f' => ['label_type' => $type] + $f, 'copies' => 1, 'which' => 'full'])->assertOk();
            $this->assertStringContainsString('('.self::BC.') Tj', $res->getContent(), "$type has the barcode");
            $this->assertSame(self::BC, LabelPrint::latest('id')->first()->fields['barcode_value']);
        }
        $this->actingAs($this->sae)->get('/labels/new?batch_id='.$b->id)->assertOk()->assertSee('name="f[barcode_value]" value="'.self::BC.'"', false)
            ->assertSee('Printed on every label (Master and Case box)');
        $j = $this->actingAs($this->sae)->postJson('/labels/preview?batch_id='.$b->id, ['f' => ['label_type' => 'case'] + $f])->json();
        $this->assertStringContainsString('data-barcode="1"', $j['svg']);
    }

    public function test_barcode_can_be_typed_on_the_form_without_a_batch(): void
    {
        $this->actingAs($this->sae)->get('/labels/new?product='.$this->product->id)->assertOk()->assertSee('name="f[barcode_value]"', false)->assertSee('No batch picked');
        $f = ['label_type' => 'case', 'title' => 'Bodega Boyz Watermelon Zkittlez 0.7g Shortiez Preroll', 'batch_label' => 'Batch #', 'batch_number' => 'HANDWRITTEN-1',
            'per_case' => '10', 'per_case_label' => 'Count per case box', 'barcode_value' => ' 1a4120300000596000009999 '];
        $res = $this->actingAs($this->sae)->post('/labels/print?product='.$this->product->id, ['f' => $f, 'copies' => 1, 'which' => 'full'])->assertOk();
        $this->assertStringContainsString('(1A4120300000596000009999) Tj', $res->getContent());
        // Required brand + nothing typed + no batch: blocked.
        $this->brand->update(['requires_barcode' => true]);
        $this->actingAs($this->sae)->post('/labels/print?product='.$this->product->id, ['f' => ['barcode_value' => ''] + $f, 'copies' => 1, 'which' => 'full'])->assertSessionHasErrors('gate');
        $j = $this->actingAs($this->sae)->postJson('/labels/preview?product='.$this->product->id, ['f' => ['barcode_value' => ''] + $f])->json();
        $this->assertTrue($j['missing_barcode']);
        $this->assertContains("This brand's labels need a barcode, and batch HANDWRITTEN-1 has no barcode number yet. Type it in the Barcode box (or add it in the Batch Log), then print.", $j['errors']);
        $this->assertSame(1, LabelPrint::count());
        $this->assertSame(0, Batch::count());
    }
}
