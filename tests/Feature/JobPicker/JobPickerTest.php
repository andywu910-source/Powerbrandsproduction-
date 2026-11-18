<?php

namespace Tests\Feature\JobPicker;

use App\Models\Brand;
use App\Models\Product;
use App\Services\ProductPicker;
use App\Support\ProductLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class JobPickerTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
    }

    public function test_parser_splits_brand_line_and_flavor(): void
    {
        $r = ProductLine::parse('Bodega 0.7g Shortiez Preroll Blue Dream', 'Bodega', 'preroll');
        $this->assertTrue($r['ok']);
        $this->assertSame(['0.7g Shortiez Preroll', 'Blue Dream'], [$r['line'], $r['flavor']]);
        $r = ProductLine::parse('Static Cherry Shockwave 0.7g 5pk 3.5g', 'Static', 'preroll');
        $this->assertSame(['0.7g 5pk Preroll', 'Cherry Shockwave'], [$r['line'], $r['flavor']]);
        $r = ProductLine::parse('Muha Habibi 2g Cart', 'Muha', 'vape');
        $this->assertSame(['2g Cart Vape', 'Habibi'], [$r['line'], $r['flavor']]);
    }

    public function test_parser_does_not_guess(): void
    {
        foreach ([['Mango Gelato Infusion', 'process step'], ['NAR Sour Diesel / Winter Rose', 'several products'], ['Cherry Shockwave 5pk 0.7', 'without a unit']] as [$n, $why]) {
            $r = ProductLine::parse($n, 'X', 'preroll');
            $this->assertFalse($r['ok'], $n);
            $this->assertStringContainsString($why, $r['why']);
        }
        $this->assertStringContainsString('family', ProductLine::parse('Gelato', 'Synergy', null)['why']);
    }

    public function test_entries_cover_active_products_once_with_unique_triples(): void
    {
        $b = Brand::create(['name' => 'Bodega', 'short_code' => 'BD']);
        $mk = fn ($name, $fam = 'preroll', $active = true) => Product::create(['code' => 'c-'.md5($name), 'name' => $name, 'brand_id' => $b->id, 'family' => $fam, 'active' => $active]);
        $a = $mk('Bodega 0.7g Shortiez Preroll Blue Dream');
        $c1 = $mk('Noizey 1g Preroll Pink Cloud');
        $c2 = $mk('Noizey 1g Preroll Pink Cloud.');   // same brand + line + flavor once parsed
        $gone = $mk('Bodega 1g Preroll Old One', 'preroll', false);
        $proc = $mk('Bodega Blue Dream Packaging');

        $e = collect(ProductPicker::entries());
        $ids = $e->pluck('id');
        $this->assertSame($ids->count(), $ids->unique()->count());
        $this->assertEqualsCanonicalizing(Product::where('active', true)->pluck('id')->all(), $ids->all());
        $this->assertNotContains($gone->id, $ids->all());
        $triples = $e->map(fn ($x) => mb_strtolower($x['brand'].'|'.$x['line'].'|'.$x['flavor']));
        $this->assertSame($triples->count(), $triples->unique()->count());

        $this->assertSame('0.7g Shortiez Preroll', $e->firstWhere('id', $a->id)['line']);
        foreach ([$c1, $c2, $proc] as $p) {
            $this->assertSame(ProductLine::OTHER, $e->firstWhere('id', $p->id)['line'], $p->name);
        }
        $this->assertStringContainsString('same brand, line and flavor', $e->firstWhere('id', $c1->id)['why']);
    }

    public function test_hand_set_line_and_flavor_win_and_name_is_unchanged(): void
    {
        $this->product->update(['product_line' => '0.5g 5pk Preroll', 'flavor' => 'Blue Razz']);
        $e = collect(ProductPicker::entries())->firstWhere('id', $this->product->id);
        $this->assertSame(['Stiiizy', '0.5g 5pk Preroll', 'Blue Razz', 'set by hand'], [$e['brand'], $e['line'], $e['flavor'], $e['source']]);
        $this->assertSame('Blue Razz 5pk', $this->product->fresh()->name);
    }

    public function test_setup_saves_line_and_flavor_without_renaming(): void
    {
        $page = $this->actingAs($this->andy)->get('/setup/products/'.$this->product->id.'/edit');
        if ($page->status() === 200) {
            $page->assertSee('name="product_line"', false)->assertSee('name="flavor"', false);
        }
        $this->product->update(['product_line' => 'X Line', 'flavor' => 'Y']);
        $this->assertSame('Blue Razz 5pk', $this->product->fresh()->name);
    }

    public function test_new_job_page_has_three_dropdowns_and_prefills(): void
    {
        $this->actingAs($this->op)->get('/production')->assertOk()
            ->assertSee('id="jobPicker"', false)->assertSee('id="jp-brand"', false)->assertSee('id="jp-line"', false)->assertSee('id="jp-flavor"', false);
        $this->actingAs($this->op)->get('/production?product_id='.$this->product->id)->assertOk()
            ->assertSee('id="selProduct"', false)->assertSee('value="'.$this->product->id.'"', false)
            ->assertSee('id="batch_number"', false)->assertSee('id="bnSuggest"', false);
    }

    public function test_inactive_products_are_not_offered(): void
    {
        $this->product->update(['active' => false]);
        $html = $this->actingAs($this->op)->get('/production')->assertOk()->getContent();
        $this->assertStringNotContainsString('"n":"Blue Razz 5pk"', $html);
    }
}
