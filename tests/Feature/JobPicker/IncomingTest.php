<?php

namespace Tests\Feature\JobPicker;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Brand;
use App\Models\Product;
use App\Support\BatchLogHelp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class IncomingTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildPlant();
    }

    private function noRecipeProduct(): Product
    {
        $b = Brand::create(['name' => 'Bodega Boyz', 'short_code' => 'BB']);

        return Product::create(['code' => 'bb-07-wzk', 'name' => 'Bodega Boyz 0.7g Shortiez Preroll Watermelon Zkittlez', 'brand_id' => $b->id, 'family' => 'preroll', 'active' => true]);
    }

    public function test_statuses_order_colours_and_tooltip(): void
    {
        $this->assertSame(['incoming', 'in_production', 'in_lab', 'packaging_coa', 'finished'], array_keys(Batch::LOG_STATUSES));
        $this->assertSame(['Incoming', 'In production', 'In lab', 'Packaging/COA labeling', 'Finished'], array_values(Batch::LOG_STATUSES));
        $this->assertSame(['#4b5563', '#b45309', '#1d4ed8', '#7e22ce', '#15803d'], array_values(Batch::LOG_STATUS_COLORS));
        foreach (['Incoming:', 'In production:', 'In lab:', 'Packaging/COA labeling:', 'Finished:', 'Start this job'] as $t) {
            $this->assertStringContainsString($t, BatchLogHelp::COLUMNS['status']);
        }
        $css = file_get_contents(public_path('css/pbp.css'));
        foreach (Batch::LOG_STATUS_COLORS as $k => $c) {
            $this->assertStringContainsString(".lst-$k { color: $c; }", $css);
        }
    }

    public function test_incoming_needs_only_product_and_unique_number(): void
    {
        $p = $this->noRecipeProduct();   // no approved recipe, no short codes: start checks would fail
        $this->actingAs($this->op)->get('/production?product_id='.$p->id)->assertOk()->assertSee('No approved MMP')->assertSee('id="incomingForm"', false);
        $this->actingAs($this->op)->post('/production/incoming', ['product_id' => $p->id])->assertSessionHasErrors('batch_number');
        $this->actingAs($this->viewer)->post('/production/incoming', ['product_id' => $p->id, 'batch_number' => 'X-1'])->assertForbidden();
        $this->actingAs($this->op)->post('/production/incoming', ['product_id' => $p->id, 'batch_number' => '  bb-wzk-001 '])->assertSessionHasNoErrors()->assertRedirect('/production');
        $b = Batch::where('batch_number', 'bb-wzk-001')->firstOrFail();
        $this->assertSame(['planned', 'incoming', null, null, null], [$b->status, $b->log_status, $b->mmp_id, $b->target_units, $b->started_at]);
        $this->assertSame(0, BatchStep::where('batch_id', $b->id)->count());
        $this->assertTrue(AuditLog::where('action', 'batch_incoming')->where('record_id', $b->id)->exists());
        // the number is reserved: any case, both for Incoming and for a normal start
        $this->actingAs($this->op)->post('/production/incoming', ['product_id' => $p->id, 'batch_number' => 'BB-WZK-001'])->assertSessionHasErrors('batch_number');
        $this->actingAs($this->andy)->post('/production', ['product_id' => $this->product->id, 'target_units' => 10, 'batch_number' => 'BB-wzk-001'])->assertSessionHasErrors('batch_number');
        // optional planned units and date
        $this->actingAs($this->op)->post('/production/incoming', ['product_id' => $this->product->id, 'batch_number' => 'PLAN-2', 'planned_units' => 300, 'planned_date' => '2026-11-24'])->assertSessionHasNoErrors();
        $b2 = Batch::where('batch_number', 'PLAN-2')->firstOrFail();
        $this->assertSame([300, '2026-11-24'], [$b2->target_units, $b2->planned_date->toDateString()]);

        $page = $this->actingAs($this->op)->get('/production')->assertOk()->getContent();
        $this->assertStringContainsString('id="incomingJobs"', $page);
        $this->assertStringContainsString('<span class="lst lst-incoming">Incoming</span>', $page);
        $log = $this->actingAs($this->op)->get('/batch-log')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="lst lst-incoming">Incoming</span>', $log);
        $this->assertStringContainsString('Start this job', $log);
        $this->actingAs($this->op)->get('/batch-log?status=incoming')->assertOk()->assertSee('PLAN-2');
        $this->actingAs($this->op)->get('/batch-log/PLAN-2')->assertOk()->assertSee('Incoming');
        $this->actingAs($this->op)->get('/production/PLAN-2')->assertRedirect('/batch-log/PLAN-2');
    }

    public function test_start_this_job_runs_the_start_checks(): void
    {
        $p = $this->noRecipeProduct();
        $this->actingAs($this->op)->post('/production/incoming', ['product_id' => $p->id, 'batch_number' => 'INC-1']);
        $b = Batch::where('batch_number', 'INC-1')->firstOrFail();
        // the status menu cannot skip the start
        $this->actingAs($this->op)->post('/batch-log/INC-1/status', ['log_status' => 'in_production'])->assertSessionHasErrors('log_status');
        $this->assertSame('incoming', $b->fresh()->log_status);
        // no approved recipe: Start is refused, the job stays Incoming
        $this->actingAs($this->op)->get('/production?start=INC-1')->assertOk()->assertSee('No approved MMP')->assertDontSee('id="btnStart"', false);
        $this->actingAs($this->op)->post('/production/INC-1/start-incoming', ['target_units' => 100])->assertSessionHasErrors();
        $this->assertSame('planned', $b->fresh()->status);

        // with a recipe it starts like any batch, keeping its number
        $this->actingAs($this->op)->post('/production/incoming', ['product_id' => $this->product->id, 'batch_number' => 'INC-2', 'planned_units' => 120]);
        $this->actingAs($this->op)->get('/production?start=INC-2')->assertOk()->assertSee('Start this job')->assertSee('value="120"', false)->assertSee('readonly', false);
        $this->actingAs($this->op)->post('/production/INC-2/start-incoming', ['target_units' => 120, 'room_id' => $this->room->id])->assertSessionHasNoErrors()->assertRedirect('/production/INC-2');
        $s = Batch::where('batch_number', 'INC-2')->firstOrFail();
        $this->assertSame(['in_process', 'in_production', 120, $this->mmp->id], [$s->status, $s->log_status, $s->target_units, $s->mmp_id]);
        $this->assertNotNull($s->started_at);
        $this->assertSame(9, BatchStep::where('batch_id', $s->id)->count());
        $a = AuditLog::where('action', 'batch_log_status')->where('record_id', $s->id)->firstOrFail();
        $this->assertSame(['incoming', 'in_production', 'start_this_job'], [$a->details['old'], $a->details['new'], $a->details['via']]);
        $this->actingAs($this->op)->post('/production/INC-2/start-incoming', ['target_units' => 120])->assertSessionHasErrors();
        // started batches can't go back to Incoming
        $this->actingAs($this->op)->post('/batch-log/INC-2/status', ['log_status' => 'incoming'])->assertSessionHasErrors('log_status');
    }

    public function test_started_statuses_change_freely_and_are_audited(): void
    {
        $b = $this->startBatch();
        foreach (['finished', 'in_lab', 'in_production', 'packaging_coa', 'in_lab'] as $to) {
            $this->actingAs($this->op)->post("/batch-log/{$b->batch_number}/status", ['log_status' => $to])->assertSessionHasNoErrors();
            $this->assertSame($to, $b->fresh()->log_status);
        }
        $this->assertSame(5, AuditLog::where('action', 'batch_log_status')->where('record_id', $b->id)->count());
        $html = $this->actingAs($this->op)->get('/batch-log')->assertOk()->getContent();
        $this->assertStringContainsString('pb-status-select lst-in_lab', $html);
        $this->assertStringContainsString('<option value="in_lab" class="lst-in_lab" selected>In lab</option>', $html);
        preg_match('~<select name="log_status".*?</select>~s', $html, $sel);
        $this->assertStringNotContainsString('value="incoming"', $sel[0], 'started batches are not offered Incoming');
        $this->assertStringContainsString('value="finished"', $sel[0]);
        $this->actingAs($this->viewer)->get('/batch-log')->assertSee('<span class="lst lst-in_lab">In lab</span>', false);
    }

    public function test_retain_until_hidden_but_kept(): void
    {
        $b = $this->startBatch(315);
        $b->update(['expiration_date' => '2027-11-01']);
        $html = $this->actingAs($this->op)->get('/batch-log')->assertOk()->getContent();
        preg_match('~<thead>(.*?)</thead>~s', $html, $m);
        $this->assertStringNotContainsString('Retain until', $m[1]);
        $this->assertStringContainsString('Retention samples needed', $m[1]);
        $this->assertSame('2027-12-01', $b->fresh()->retainUntil()->toDateString());
    }
}
