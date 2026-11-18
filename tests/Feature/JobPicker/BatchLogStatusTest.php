<?php

namespace Tests\Feature\JobPicker;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Services\LabelService;
use App\Services\SignatureService;
use App\Support\BatchLogHelp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class BatchLogStatusTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildPlant();
    }

    public function test_backfill_rule(): void
    {
        $r = fn (array $b) => Batch::logStatusRule($b);
        $this->assertSame('finished', $r(['status' => 'imported', 'source' => 'sheet', 'current_step' => 1]));
        $this->assertSame('finished', $r(['status' => 'released', 'current_step' => 9]));
        $this->assertSame('finished', $r(['status' => 'rejected', 'current_step' => 9]));
        $this->assertSame('packaging_coa', $r(['status' => 'in_qc', 'current_step' => 9]));
        $this->assertSame('packaging_coa', $r(['status' => 'in_process', 'current_step' => 7]));
        $this->assertSame('packaging_coa', $r(['status' => 'in_process', 'current_step' => 8]));
        $this->assertSame('in_production', $r(['status' => 'in_process', 'current_step' => 6]));
        $this->assertSame('in_production', $r(['status' => 'void', 'current_step' => 2]));
        $this->assertSame('finished', $r(['status' => 'void', 'source' => 'sheet']));
    }

    public function test_new_batch_starts_in_production_and_status_change_is_audited(): void
    {
        $b = $this->startBatch();
        $this->assertSame('in_production', $b->fresh()->log_status);
        $html = $this->actingAs($this->op)->get('/batch-log')->assertOk()->getContent();
        $this->assertStringContainsString('name="log_status"', $html);
        foreach (['In production', 'Packaging/COA labeling', 'Finished'] as $l) {
            $this->assertStringContainsString($l, $html);
        }

        $this->actingAs($this->op)->from('/batch-log')->post("/batch-log/{$b->batch_number}/status", ['log_status' => 'packaging_coa'])->assertRedirect('/batch-log')->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/batch-log/{$b->batch_number}/status", ['log_status' => 'finished'])->assertRedirect();
        $b->refresh();
        $this->assertSame('finished', $b->log_status);
        $this->assertSame('in_process', $b->status, 'the production record status is untouched');
        $rows = AuditLog::where('action', 'batch_log_status')->where('record_id', $b->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['in_production', 'packaging_coa', $this->op->id], [$rows[0]->details['old'], $rows[0]->details['new'], $rows[0]->user_id]);
        $this->assertSame(['packaging_coa', 'finished'], [$rows[1]->details['old'], $rows[1]->details['new']]);
        $this->actingAs($this->op)->getJson('/batch-log?status=finished')->assertOk()->assertSee($b->batch_number);
    }

    public function test_viewer_bad_value_and_void_are_refused(): void
    {
        $b = $this->startBatch();
        $this->actingAs($this->viewer)->post("/batch-log/{$b->batch_number}/status", ['log_status' => 'finished'])->assertForbidden();
        $this->actingAs($this->op)->post("/batch-log/{$b->batch_number}/status", ['log_status' => 'released'])->assertSessionHasErrors('log_status');
        $html = $this->actingAs($this->viewer)->get('/batch-log')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="log_status"', $html);
        $b->update(['status' => 'void']);
        $this->actingAs($this->op)->post("/batch-log/{$b->batch_number}/status", ['log_status' => 'finished'])->assertSessionHasErrors('log_status');
        $this->assertSame('in_production', $b->fresh()->log_status);
        $this->assertSame(0, AuditLog::where('action', 'batch_log_status')->count());
    }

    public function test_columns_removed_and_tips_present(): void
    {
        $this->startBatch();
        $html = $this->actingAs($this->op)->get('/batch-log')->assertOk()->getContent();
        preg_match('~<thead>(.*?)</thead>~s', $html, $m);
        foreach (['>Packed', '>Finished', '>QC', '>Yield', 'What is “Yield”', 'Retain until'] as $gone) {
            $this->assertStringNotContainsString($gone, $m[1]);
        }
        foreach (['Batch number', 'Product', 'Brand', 'Status', 'Barcode number', 'Units planned', 'Lab samples (per test)', 'Retention samples needed'] as $col) {
            $this->assertStringContainsString('aria-label="What is “'.$col.'”?"', $m[1]);
        }
        $this->assertStringContainsString(e(BatchLogHelp::COLUMNS['status']), $m[1]);
        $this->assertArrayNotHasKey('yield', BatchLogHelp::COLUMNS);
        $this->assertStringContainsString('id="pbTipPop"', $html);
    }

    public function test_no_qc_step_labels_do_not_warn_and_qc_code_stays_dormant(): void
    {
        $b = $this->startBatch();
        $svc = app(LabelService::class);
        $f = ['batch_number' => $b->batch_number, 'total' => 10, 'per_case' => 10, 'cases' => 1, 'label_type' => 'unit', 'barcode_on' => false, 'barcode_value' => '', 'partial' => false];
        $L = ['fits' => true, 'explicit' => true, 'size' => 99, 'lines' => [], 'overlaps_barcode' => false];
        $notReleased = fn () => collect($svc->warnings($f, $b->fresh(), $L))->contains(fn ($x) => str_contains($x, 'QC-released'));
        $this->assertFalse($notReleased(), 'no QC step: labels do not warn about QC release');
        config(['pbp.qc_release_step' => true]);
        $this->assertTrue($notReleased(), 'the old warning is still there behind the switch');
        config(['pbp.qc_release_step' => false]);

        $this->throughStep4($b);
        $this->throughStep8($b);
        $page = $this->actingAs($this->op)->get("/production/{$b->batch_number}")->assertOk()->getContent();
        $this->assertStringNotContainsString('Release batch', $page);
        $this->assertStringNotContainsString('QC review &amp; release', $page);
        $this->assertStringContainsString('Save COA details', $page);
        $this->actingAs($this->op)->get("/batch-log/{$b->batch_number}")->assertOk()->assertDontSee('QC decision');
        // dormant rule kept in code: someone who worked on the batch still cannot be its QC reviewer
        $worker = SignatureService::batchWorkerIds($b->fresh())[0];
        $this->actingAs($this->andy)->post("/production/{$b->batch_number}/qc", ['decision' => 'released', 'signer_id' => $worker]);
        $this->assertNotSame('released', $b->fresh()->status);
    }

    public function test_label_barcode_rule_is_unchanged(): void
    {
        $f = ['batch_number' => 'PB-1', 'title' => 'Stiiizy Blue Razz', 'total' => 10, 'per_case' => 10, 'cases' => 1, 'barcode_value' => ''];
        $svc = app(LabelService::class);
        $this->assertTrue(collect($svc->errors($f + ['barcode_required' => true], 1))->contains(fn ($x) => str_contains($x, 'need a barcode')));
        $this->assertFalse(collect($svc->errors($f + ['barcode_required' => false], 1))->contains(fn ($x) => str_contains($x, 'need a barcode')));
    }
}
