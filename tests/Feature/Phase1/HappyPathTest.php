<?php

namespace Tests\Feature\Phase1;

use App\Models\Batch;
use App\Models\MaterialLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class HappyPathTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildPlant();
    }

    public function test_batch_runs_all_nine_steps_and_qc_release_is_independent(): void
    {
        $b = $this->startBatch();
        $this->actingAs($this->op)->get("/production/{$b->batch_number}")->assertOk()->assertSee($b->batch_number);
        $this->throughStep4($b);
        $this->throughStep8($b);

        $b->refresh();
        $this->assertSame('in_qc', $b->status);
        $this->assertEqualsWithDelta(950.0, (float) MaterialLot::find($this->flowerLot->id)->current_balance, 0.001);
        $this->assertEqualsWithDelta(400.0, (float) MaterialLot::find($this->conesLot->id)->current_balance, 0.001);

        // Leo verified work on this batch, so Leo cannot release it.
        $this->actingAs($this->leo)->post("/production/{$b->batch_number}/qc", ['decision' => 'released', 'signer_id' => $this->leo->id])->assertSessionHasErrors('gate');
        // A non-QC person cannot release.
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/qc", ['decision' => 'released', 'signer_id' => $this->op->id])->assertSessionHasErrors('gate');
        $this->assertSame('in_qc', $b->fresh()->status);

        $this->actingAs($this->jenny)->post("/production/{$b->batch_number}/qc", ['decision' => 'released', 'signer_id' => $this->jenny->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('released', $b->fresh()->status);
        $this->actingAs($this->andy)->get("/production/{$b->batch_number}")->assertOk();
    }
}
