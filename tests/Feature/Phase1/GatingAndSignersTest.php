<?php

namespace Tests\Feature\Phase1;

use App\Models\Batch;
use App\Models\BatchMaterialLine;
use App\Models\Deviation;
use App\Models\MmpStep;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class GatingAndSignersTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildPlant();
    }

    public function test_steps_cannot_be_skipped_even_by_admin(): void
    {
        $b = $this->startBatch();
        $this->complete($b, 3, ['performed' => $this->andy->id, 'verified' => $this->leo->id])->assertStatus(409)->assertSee('must be completed first');
        $this->complete($b, 9, [])->assertSessionHasErrors('gate');
        $this->assertSame(1, $b->fresh()->current_step);
        $this->complete($b, 1, ['issued' => $this->leo->id])->assertSessionHasNoErrors();
        $this->complete($b, 1, ['issued' => $this->leo->id])->assertStatus(409);
    }

    public function test_batch_issue_needs_a_supervisor(): void
    {
        $b = $this->startBatch();
        $this->complete($b, 1, ['issued' => $this->op->id])->assertSessionHasErrors('gate');
        $this->complete($b, 1, [])->assertSessionHasErrors('gate');
        $this->assertSame(0, Signature::count());
    }

    public function test_verifier_must_differ_from_performer(): void
    {
        $b = $this->startBatch();
        $this->throughStep4($b);
        $bn = $b->batch_number;
        $this->actingAs($this->op)->post("/production/{$bn}/calculate")->assertSessionHasNoErrors();
        // Calc must be confirmed by a supervisor.
        $this->actingAs($this->op)->post("/production/{$bn}/calculate/confirm", ['signer_id' => $this->op->id])->assertSessionHasErrors('gate');
        $this->actingAs($this->leo)->post("/production/{$bn}/calculate/confirm", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        $line = $this->mmp->lines()->first();
        $this->actingAs($this->op)->post("/production/{$bn}/pick", ['mmp_line_id' => $line->id, 'material_lot_id' => $this->flowerLot->id, 'qty' => 50])->assertSessionHasNoErrors();
        $ml = BatchMaterialLine::where('batch_id', $b->id)->firstOrFail();
        $this->actingAs($this->op)->post("/production/{$bn}/lines/{$ml->id}/weigh", ['actual_qty' => 50, 'scale_equipment_id' => $this->scale->id, 'signer_id' => $this->op->id])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$bn}/lines/{$ml->id}/verify", ['signer_id' => $this->op->id])->assertSessionHasErrors('gate');
        $this->assertSame('weighed', $ml->fresh()->status);
        $this->actingAs($this->op)->post("/production/{$bn}/lines/{$ml->id}/verify", ['signer_id' => $this->sae->id])->assertSessionHasNoErrors();
        $this->assertSame('verified', $ml->fresh()->status);
    }

    public function test_step_verifier_same_as_performer_is_refused(): void
    {
        $b = $this->startBatch();
        $this->complete($b, 1, ['issued' => $this->leo->id]);
        $b->steps()->where('step_no', 2)->update(['status' => 'complete']);
        $b->steps()->where('step_no', 3)->update(['status' => 'open']);
        $b->update(['current_step' => 3]);
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/equipment", ['equipment_id' => $this->scale->id, 'condition_ok' => 1]);
        $this->complete($b, 3, ['performed' => $this->leo->id, 'verified' => $this->leo->id], ['staff' => [$this->pOp->id]])
            ->assertSessionHasErrors(['gate' => 'The verifier must be a different person from who performed the step.']);
    }

    public function test_out_of_tolerance_needs_deviation_and_qc_approval(): void
    {
        $b = $this->startBatch();
        $this->throughStep4($b);
        [$flowerLine] = $this->batching($b, 60);   // 20% over
        $ml = BatchMaterialLine::find($flowerLine);
        $this->assertFalse((bool) $ml->within_tolerance);
        $this->complete($b, 5, ['completed' => $this->leo->id], ['staff' => [$this->pOp->id]])->assertSessionHasErrors('gate');

        $this->actingAs($this->op)->post("/production/{$b->batch_number}/lines/{$ml->id}/deviation", ['description' => 'Overweighed flower', 'signer_id' => $this->op->id])->assertSessionHasNoErrors();
        $dev = Deviation::where('batch_id', $b->id)->firstOrFail();
        $this->assertTrue((bool) $dev->requires_qc_approval, 'Active ingredient deviations need QC approval');
        $this->complete($b, 5, ['completed' => $this->leo->id], ['staff' => [$this->pOp->id]])->assertSessionHasErrors('gate');

        // Operators are not QC reviewers.
        $this->actingAs($this->op)->post("/deviations/{$dev->id}/approve", ['signer_id' => $this->op->id])->assertSessionHasErrors('gate');
        $this->actingAs($this->jenny)->post("/deviations/{$dev->id}/approve", ['signer_id' => $this->jenny->id])->assertSessionHasNoErrors();
        $this->complete($b, 5, ['completed' => $this->leo->id], ['staff' => [$this->pOp->id]])->assertSessionHasNoErrors();
        $this->assertSame(6, $b->fresh()->current_step);
        $this->actingAs($this->andy)->get("/deviations/{$dev->id}")->assertOk()->assertSee($dev->public_id);
        $this->actingAs($this->andy)->get('/deviations')->assertOk()->assertSee($dev->public_id);
    }

    public function test_open_deviation_blocks_qc_release_until_closed(): void
    {
        $b = $this->startBatch();
        $this->throughStep4($b);
        $this->throughStep8($b);
        $this->actingAs($this->op)->post('/deviations', ['batch_id' => $b->id, 'title' => 'Spill', 'description' => 'Small spill', 'signer_id' => $this->op->id])->assertSessionHasNoErrors();
        $dev = Deviation::where('batch_id', $b->id)->firstOrFail();
        $this->actingAs($this->jenny)->post("/production/{$b->batch_number}/qc", ['decision' => 'released', 'signer_id' => $this->jenny->id])->assertSessionHasErrors('gate');
        $this->actingAs($this->jenny)->post("/deviations/{$dev->id}/close", ['root_cause' => 'Bump', 'corrective_action' => 'Retrain', 'disposition' => 'No impact', 'signer_id' => $this->jenny->id])->assertSessionHasNoErrors();
        $this->actingAs($this->jenny)->post("/production/{$b->batch_number}/qc", ['decision' => 'released', 'signer_id' => $this->jenny->id])->assertSessionHasNoErrors();
        $this->assertSame('released', $b->fresh()->status);
    }

    public function test_mmp_steps_go_in_order_and_out_of_limit_needs_deviation(): void
    {
        $b = $this->startBatch();
        $this->throughStep4($b);
        $this->batching($b);
        $this->complete($b, 5, ['completed' => $this->leo->id], ['staff' => [$this->pOp->id]])->assertSessionHasNoErrors();
        [$s1, $s2] = $this->mmp->steps()->orderBy('step_no')->get();
        $bn = $b->batch_number;
        $this->actingAs($this->op)->post("/production/{$bn}/mmp/{$s2->id}", ['actual_value' => 0.5, 'signer_id' => $this->op->id, 'staff' => [$this->pOp->id]])->assertStatus(409);
        $this->actingAs($this->op)->post("/production/{$bn}/mmp/{$s1->id}", ['actual_value' => 50, 'signer_id' => $this->op->id, 'staff' => [$this->pOp->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$bn}/mmp/{$s2->id}", ['actual_value' => 0.5, 'signer_id' => $this->op->id, 'staff' => [$this->pOp->id]])->assertStatus(409); // step 1 not verified
        $rec = $b->mmpStepRecords()->firstOrFail();
        $this->actingAs($this->op)->post("/production/{$bn}/mmp-records/{$rec->id}/verify", ['signer_id' => $this->op->id])->assertSessionHasErrors('gate');
        $this->actingAs($this->leo)->post("/production/{$bn}/mmp-records/{$rec->id}/verify", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$bn}/mmp/{$s2->id}", ['actual_value' => 0.7, 'signer_id' => $this->op->id, 'staff' => [$this->pOp->id]])->assertSessionHasNoErrors();
        $r2 = $b->mmpStepRecords()->where('mmp_step_id', $s2->id)->firstOrFail();
        $this->assertFalse((bool) $r2->within_limit);
    }

    public function test_step6_only_offers_stages_for_the_product_family(): void
    {
        MmpStep::create(['mmp_id' => $this->mmp->id, 'step_no' => 4, 'stage_code' => 'mixing', 'instruction' => 'Vape mixing', 'requires_verifier' => false]);
        $b = $this->startBatch();
        $codes = app(\App\Services\ProductionWorkflow::class)->applicableMmpSteps($b)->pluck('stage_code')->all();
        $this->assertSame(['grinding', 'filling', 'packaging'], $codes);
    }

    public function test_jack_and_viewers_cannot_sign_and_viewers_cannot_write(): void
    {
        $jackUser = User::factory()->create(['name' => 'Jack']);
        $this->pJack->update(['user_id' => $jackUser->id]);
        $this->assertFalse(User::signers()->contains('id', $jackUser->id));
        $this->assertFalse(User::signers()->contains('id', $this->viewer->id));

        $b = $this->startBatch();
        $this->complete($b, 1, ['issued' => $jackUser->id])->assertSessionHasErrors('gate');
        $this->actingAs($this->viewer)->get("/production/{$b->batch_number}")->assertOk();
        $this->actingAs($this->viewer)->post("/production/{$b->batch_number}/steps/1/complete", ['signers' => ['issued' => $this->leo->id]])->assertForbidden();
        $this->actingAs($this->viewer)->post('/production', ['product_id' => $this->product->id, 'target_units' => 10])->assertForbidden();
        $this->assertSame(1, Batch::count());
    }

    public function test_crew_needs_training_and_ppe(): void
    {
        $b = $this->startBatch();
        $this->complete($b, 1, ['issued' => $this->leo->id]);
        $b->steps()->whereIn('step_no', [2, 3])->update(['status' => 'complete']);
        $b->steps()->where('step_no', 4)->update(['status' => 'open']);
        $b->update(['current_step' => 4]);
        $p = \App\Models\Person::create(['full_name' => 'New Hire', 'initials' => 'NH', 'active' => true]);
        $this->actingAs($this->leo)->post("/production/{$b->batch_number}/people", ['person_id' => $p->id, 'ppe_ok' => 1])->assertSessionHasErrors('gate');
        $this->actingAs($this->leo)->post("/production/{$b->batch_number}/people", ['person_id' => $this->pSae->id])->assertSessionHasErrors('gate');
    }
}
