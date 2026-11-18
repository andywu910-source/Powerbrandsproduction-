<?php

namespace Tests\Feature\Phase1;

use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

/** Hotfix 1: typing a product (or an odd batch number) in the Labels batch picker gave "404 not found". */
class LabelBatchPickerTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private array $odd = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,labels']);
        $this->brand->update(['name' => 'Bodega Boyz', 'requires_barcode' => false]);
        $this->product->update(['name' => 'Watermelon Zkittlez 0.7g']);
        $b = $this->startBatch(320);
        // Live-like imported batch numbers: spaces, slash, "- FAILED LAB", near-duplicates, '#'.
        $row = (array) DB::table('batches')->where('id', $b->id)->first();
        foreach (['MM35SB527 - FAILED LAB', 'BB/WZK/1117', 'BBWZK 261117', 'bbwzk261117-2', 'PH#22 R&D?x=1'] as $i => $n) {
            $new = array_merge($row, ['batch_number' => $n, 'public_id' => 'BPR-ODD'.$i, 'status' => 'released']);
            unset($new['id']);
            $this->odd[$n] = DB::table('batches')->insertGetId($new);
        }
    }

    public function test_typing_a_product_name_searches_instead_of_404(): void
    {
        $r = $this->actingAs($this->sae)->get('/labels/new?q='.urlencode('watermelon zkittlez'))->assertOk();
        $r->assertSee('batches match')->assertSee('MM35SB527 - FAILED LAB')->assertSee('BB/WZK/1117')->assertSee('labels/new?batch_id='.$this->odd['BB/WZK/1117'], false);
        $this->actingAs($this->sae)->get('/labels/new?q=nothing-like-this')->assertOk()->assertSee('No batch matches');
        // Old-style link with a non-existing batch text: search page, not 404.
        $this->actingAs($this->sae)->get('/labels/new?batch='.urlencode('Bodega Boyz'))->assertOk()->assertSee('batches match');
        $this->actingAs($this->sae)->get('/labels/new?q='.urlencode('brand-that-is-not-there'))->assertOk();
    }

    public function test_picking_odd_batch_numbers_by_id_or_option_text_opens_the_form(): void
    {
        foreach ($this->odd as $n => $id) {
            $this->actingAs($this->sae)->get('/labels/new?batch_id='.$id)->assertOk()->assertSee('value="'.e($n).'"', false);
            // Picker without JS: the option text "NUMBER · product" is sent as q.
            $this->actingAs($this->sae)->get('/labels/new?q='.urlencode($n.' · Bodega Boyz Watermelon Zkittlez 0.7g'))->assertOk()->assertSee('name="f[batch_number]" value="'.e($n).'"', false);
            $this->actingAs($this->sae)->get('/labels/new?q='.urlencode($n))->assertOk()->assertSee('name="f[batch_number]" value="'.e($n).'"', false);
        }
        // Single search hit opens it straight away.
        $this->actingAs($this->sae)->get('/labels/new?q=MM35SB527')->assertOk()->assertSee('name="f[batch_number]" value="MM35SB527 - FAILED LAB"', false);
        // Preview and print use the id.
        $id = $this->odd['BB/WZK/1117'];
        $f = ['label_type' => 'case', 'title' => 'X', 'batch_label' => 'Batch #', 'batch_number' => 'BB/WZK/1117', 'per_case' => '10', 'per_case_label' => 'Count per case box'];
        $this->actingAs($this->sae)->postJson('/labels/preview?batch_id='.$id, ['f' => $f])->assertOk()->assertJsonPath('errors', []);
        $this->actingAs($this->sae)->post('/labels/print?batch_id='.$id, ['f' => $f, 'copies' => 1, 'which' => 'full'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($id, \App\Models\LabelPrint::latest('id')->first()->batch_id);
        $this->actingAs($this->sae)->get('/labels/new?batch_id=999999')->assertNotFound();
    }

    public function test_search_endpoint_and_picker_markup(): void
    {
        $j = $this->actingAs($this->sae)->getJson('/labels/batches?q='.urlencode('failed lab'))->assertOk()->json('items');
        $this->assertSame(['MM35SB527 - FAILED LAB'], array_column($j, 'batch_number'));
        $this->assertStringContainsString('batch_id='.$this->odd['MM35SB527 - FAILED LAB'], $j[0]['url']);
        $this->assertCount(6, $this->actingAs($this->sae)->getJson('/labels/batches?q=zkittlez')->json('items'));
        $this->actingAs($this->sae)->get('/labels')->assertOk()->assertSee('name="batch_id"', false)->assertSee('id="batchDrop"', false)
            ->assertSee('role="combobox"', false)->assertSee('labels\\/batches', false);
    }

    public function test_release_check_batch_pick(): void
    {
        $code = \Illuminate\Support\Facades\Artisan::call('pbp:release-check', ['--batch-pick' => true]);
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('OK    typing the product name and pressing Use this batch returns 200', $out);
        $this->assertStringContainsString('OK    picking batch', $out);
    }

    public function test_batch_urls_with_odd_numbers_use_the_id(): void
    {
        $b = Batch::findOrFail($this->odd['BB/WZK/1117']);
        $this->assertSame('~'.$b->id, $b->getRouteKey());
        $this->actingAs($this->sae)->get(route('batch-log.show', $b))->assertOk()->assertSee('BB/WZK/1117');
        $ok = Batch::where('batch_number', 'bbwzk261117-2')->first();
        $this->assertSame('bbwzk261117-2', $ok->getRouteKey());
        $this->actingAs($this->sae)->get("/batch-log/{$ok->batch_number}")->assertOk();
        $this->actingAs($this->leo)->get(route('batch-log.show', $b))->assertSee('labels/new?batch_id='.$b->id, false);
    }
}
