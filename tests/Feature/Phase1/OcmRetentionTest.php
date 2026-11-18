<?php

namespace Tests\Feature\Phase1;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Support\OcmSampling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

/** OCM Sampling QS Standard: Table 1 lab samples, 2x retention (item C), 150,000-unit cap (item B). */
class OcmRetentionTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
    }

    public function test_table_1_boundaries_single_and_multi(): void
    {
        $cases = [
            [1, 5, 2], [500, 5, 2], [501, 8, 3], [1200, 8, 3], [1201, 13, 3], [3200, 13, 3], [3201, 20, 4],
            [10000, 20, 4], [10001, 32, 5], [35000, 32, 5], [35001, 50, 6], [150000, 50, 6],
        ];
        foreach ($cases as [$n, $single, $multi]) {
            $this->assertSame($single, OcmSampling::perTest($n, 'single'), "$n single");
            $this->assertSame($multi, OcmSampling::perTest($n, 'multi'), "$n multi");
            $this->assertSame(2 * $single, OcmSampling::retention($n, 'single'));
            $this->assertSame(2 * $multi, OcmSampling::retention($n, 'multi'));
        }
        $this->assertNull(OcmSampling::perTest(150001));
        $this->assertNull(OcmSampling::retention(150001));
        $this->assertNull(OcmSampling::perTest(0));
        $this->assertSame(5, OcmSampling::perTest(315), '315 pre-rolls: 5 per test');
        $this->assertSame(10, OcmSampling::retention(315), '315 pre-rolls: 10 retained');
    }

    public function test_multi_serving_only_for_edibles_and_capsules(): void
    {
        $this->assertTrue(OcmSampling::multiAllowed(null, 'Gummies', null));
        $this->assertTrue(OcmSampling::multiAllowed(null, 'Capsules', null));
        $this->assertTrue(OcmSampling::multiAllowed('green_mantis', 'Chocolate bar', null));
        foreach ([['preroll', 'Gummies'], ['flower', 'ground'], ['vape', 'AIO'], ['rosin', 'live rosin'], ['green_mantis', 'tincture'], [null, 'hash oil'], [null, 'topical']] as [$fam, $type]) {
            $this->assertFalse(OcmSampling::multiAllowed($fam, $type, null), "$fam / $type");
        }
        // A product saved as multi but not eligible is still computed as single-serve.
        $this->product->forceFill(['serving_type' => 'multi'])->save();
        $this->assertSame('single', OcmSampling::servingFor($this->product->fresh()));

        // Setup refuses multi for a pre-roll, accepts it for an edible.
        $base = ['name' => $this->product->name, 'brand_id' => $this->brand->id, 'short_code' => '5BR', 'family' => 'preroll', 'unit_net_qty' => 0.5, 'unit_net_uom' => 'g', 'case_pack_qty' => 10, 'active' => '1'];
        $this->actingAs($this->andy)->put("/setup/products/{$this->product->id}", $base + ['serving_type' => 'multi'])->assertSessionHasErrors('gate');
        $this->assertStringContainsString('footnote A', session('errors')->first('gate'));
        $this->actingAs($this->andy)->put("/setup/products/{$this->product->id}", ['family' => 'green_mantis', 'product_type' => 'Gummies'] + $base + ['serving_type' => 'multi'])->assertSessionHasNoErrors();
        $this->assertSame('multi', OcmSampling::servingFor($this->product->fresh()));
        $this->actingAs($this->andy)->put("/setup/products/{$this->product->id}", $base)->assertSessionHasNoErrors();
        $this->assertSame('single', $this->product->fresh()->serving_type, 'defaults to single');
    }

    public function test_batch_log_columns_and_retain_until(): void
    {
        config(['pbp.modules' => 'production,receiving,checklists,batchlog']);
        $b = $this->startBatch(315);
        $this->assertSame(5, $b->labSamplesPerTest());
        $this->assertSame(10, $b->retentionSamples());
        $this->assertNull($b->retainUntil());

        $r = $this->actingAs($this->sae)->get('/batch-log?status=all')->assertOk();
        $r->assertSee('Lab samples (per test)')->assertSee('Retention samples needed')->assertDontSee('Set expiration date')   // Retain until column hidden (Andy, Nov 17); data kept
            ->assertSee(OcmSampling::INFO);
        $this->assertMatchesRegularExpression('#class="js-lab"[^>]*>5 <span#', $r->getContent());
        $this->assertMatchesRegularExpression('#class="js-ret"[^>]*>10</td>#', $r->getContent());

        // Operators can't set the expiration date; supervisors can (audited); retain until = +30 days.
        $this->actingAs($this->sae)->post("/batch-log/{$b->batch_number}/expiration", ['expiration_date' => '2027-11-01'])->assertForbidden();
        $this->actingAs($this->leo)->post("/batch-log/{$b->batch_number}/expiration", ['expiration_date' => '2027-11-01'])->assertRedirect();
        $b = $b->fresh();
        $this->assertSame('2027-12-01', $b->retainUntil()->toDateString());
        $this->assertTrue(AuditLog::where('record_type', 'batch')->where('record_id', $b->id)->get()->contains(fn ($a) => str_contains(json_encode($a->details), '2027-11-01')));
        $this->actingAs($this->sae)->get("/batch-log/{$b->batch_number}")->assertOk()->assertSee('OCM samples and retention')->assertSee('Retention samples needed');
        $this->assertSame('2028-03-01', OcmSampling::retainUntil(Carbon::parse('2028-01-31'))->toDateString());
        $this->actingAs($this->sae)->get('/batch-log?status=all')->assertDontSee('Set expiration date');
    }

    public function test_over_150000_units_is_blocked(): void
    {
        $n = Batch::count();
        $this->actingAs($this->andy)->post('/production', ['product_id' => $this->product->id, 'target_units' => 150001, 'room_id' => $this->room->id])
            ->assertSessionHasErrors(['target_units' => OcmSampling::TOO_BIG]);
        $this->assertSame($n, Batch::count());
        $this->actingAs($this->andy)->post('/production', ['product_id' => $this->product->id, 'target_units' => 150000, 'room_id' => $this->room->id, 'batch_number' => 'OCM-MAX-1'])->assertSessionHasNoErrors();
        $this->assertSame($n + 1, Batch::count());
        $probs = app(\App\Services\ProductionWorkflow::class)->setupProblems($this->product->fresh(), $this->product->currentMmp(), 150001, null);
        $this->assertContains(OcmSampling::TOO_BIG, $probs, json_encode($probs));
    }

    public function test_create_form_shows_live_samples(): void
    {
        $r = $this->actingAs($this->andy)->get('/production?product_id='.$this->product->id)->assertOk();
        $r->assertSee('id="ocmSamples" data-serving="single"', false)->assertSee('Lab samples (per test)')->assertSee('Retention samples needed')
            ->assertSee('max="150000"', false)->assertSee('"rows":[[500,5,2],[1200,8,3]', false)->assertSee(OcmSampling::TOO_BIG);
    }

    public function test_release_check_ocm(): void
    {
        config(['pbp.modules' => 'production,receiving,checklists,batchlog']);
        $this->startBatch(315);
        $code = \Illuminate\Support\Facades\Artisan::call('pbp:release-check', ['--ocm' => true, '--column' => ['products.serving_type', 'batches.expiration_date']]);
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('OK    Batch Log shows the lab / retention sample columns', $out);
    }
}
