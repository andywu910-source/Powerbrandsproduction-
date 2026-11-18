<?php

namespace Tests\Feature\Phase1;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class BatchLogTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildPlant();
    }

    private function releasedBatch()
    {
        $b = $this->startBatch();
        $this->throughStep4($b);
        $this->throughStep8($b);
        $this->actingAs($this->jenny)->post("/production/{$b->batch_number}/qc", ['decision' => 'released', 'signer_id' => $this->jenny->id])
            ->assertSessionHasNoErrors()->assertRedirect("/batch-log/{$b->batch_number}");

        return $b->fresh();
    }

    public function test_list_summary_print_and_pdf(): void
    {
        $b = $this->releasedBatch();
        $open = $this->startBatch();

        $this->actingAs($this->op)->get('/batch-log')->assertOk()->assertSee($b->batch_number)->assertSee($open->batch_number);   // default: every batch that is not void
        $html = $this->actingAs($this->op)->get('/batch-log?status=finished')->assertOk()->assertSee($b->batch_number)->getContent();
        $this->assertStringNotContainsString(">{$open->batch_number}<", $html);
        $this->actingAs($this->op)->get('/batch-log?status=in_production')->assertOk()->assertSee($open->batch_number);
        $this->actingAs($this->op)->get('/batch-log?q='.substr($b->batch_number, 0, 7))->assertOk()->assertSee($b->batch_number);

        $this->actingAs($this->viewer)->get("/batch-log/{$b->batch_number}")->assertOk()
            ->assertSee('Post-batch summary')->assertSee('Released')->assertSee('Ground flower')->assertSee($this->flowerLot->public_id)
            ->assertSee('Jenny')->assertSee('Lab A')->assertSee('Download PDF');

        $this->actingAs($this->op)->get("/batch-log/{$b->batch_number}/print")->assertOk()->assertSee('Batch record summary')->assertSee('SHA-256');

        $pdf = $this->actingAs($this->op)->get("/batch-log/{$b->batch_number}/pdf");
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertGreaterThan(3000, strlen($pdf->getContent()));
        $this->assertTrue(AuditLog::where('action', 'export')->exists());
    }

    public function test_batch_log_is_off_when_module_is_off(): void
    {
        config(['pbp.modules' => 'production,receiving']);
        $b = $this->startBatch();
        $this->actingAs($this->op)->get('/batch-log')->assertNotFound();
        $this->actingAs($this->op)->get("/batch-log/{$b->batch_number}/pdf")->assertNotFound();
    }

    public function test_labels_tab_is_live_in_m4a(): void
    {
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,labels']);
        $this->actingAs($this->op)->get('/dashboard')->assertOk()->assertSee(route('labels.index'), false)->assertDontSee('/soon/labels', false);
        $this->actingAs($this->op)->get('/labels')->assertOk()->assertSee('Master box and case box labels');
        config(['pbp.modules' => 'production,receiving,checklists,batchlog']);
        $this->actingAs($this->op)->get('/labels')->assertNotFound();   // tab off
        $this->actingAs($this->op)->get('/soon/labels')->assertOk()->assertSee('case / carton labels');
    }
}
