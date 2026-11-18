<?php

namespace Tests\Feature\Phase1;

use App\Models\ChecklistInstance;
use App\Models\ChecklistTemplate;
use App\Models\Signature;
use App\Services\ChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class ChecklistsTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
    }

    public function test_generation_is_idempotent(): void
    {
        $svc = app(ChecklistService::class);
        $first = $svc->generate();
        $this->assertGreaterThan(0, $first['created']);
        $count = ChecklistInstance::count();
        $this->assertSame(0, $svc->generate()['created']);
        $this->artisan('checklists:generate')->assertSuccessful();
        $this->assertSame($count, ChecklistInstance::count());
    }

    public function test_complete_verify_rules_and_no_double_signing(): void
    {
        $this->travelTo(now(config('pbp.site_timezone'))->setTime(9, 0));
        $t = ChecklistTemplate::where('code', 'daily_room_clean')->firstOrFail();
        app(ChecklistService::class)->generate();
        $i = ChecklistInstance::where('template_id', $t->id)->firstOrFail();
        $this->actingAs($this->op)->get("/checklists/{$i->id}")->assertOk();

        // Unanswered -> refused.
        $this->actingAs($this->op)->post("/checklists/{$i->id}/complete", ['signer_id' => $this->op->id])->assertSessionHasErrors('gate');
        // A failed item needs a note.
        $answers = $this->answers($i);
        $first = array_key_first($answers);
        $answers[$first]['response'] = 'fail';
        $this->actingAs($this->op)->post("/checklists/{$i->id}/complete", ['items' => $answers, 'signer_id' => $this->op->id])->assertSessionHasErrors('gate');
        $answers[$first]['note'] = 'Re-cleaned and checked again';
        $this->actingAs($this->op)->post("/checklists/{$i->id}/complete", ['items' => $answers, 'signer_id' => $this->op->id])->assertSessionHasNoErrors();
        $i->refresh();
        $this->assertSame('awaiting_verification', $i->status);

        $this->actingAs($this->op)->post("/checklists/{$i->id}/complete", ['items' => $answers, 'signer_id' => $this->op->id])->assertSessionHasErrors('gate');
        $this->actingAs($this->op)->post("/checklists/{$i->id}/verify", ['signer_id' => $this->op->id])->assertSessionHasErrors('gate');
        $this->actingAs($this->leo)->post("/checklists/{$i->id}/verify", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        $this->actingAs($this->leo)->post("/checklists/{$i->id}/verify", ['signer_id' => $this->leo->id])->assertSessionHasErrors('gate');
        $this->assertSame('completed', $i->fresh()->status);
        $this->assertSame(2, Signature::where('signable_type', 'checklist')->where('signable_id', $i->id)->count());
        $this->actingAs($this->op)->post("/checklists/{$i->id}/answers", ['items' => $answers])->assertSessionHasErrors('gate');
    }

    public function test_changeover_cleaning_starts_once_per_batch(): void
    {
        $b = $this->startBatch();
        $this->complete($b, 1, ['issued' => $this->leo->id]);
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/cleaning")->assertRedirect();
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/cleaning")->assertSessionHasErrors('gate');
        $this->assertSame(1, ChecklistInstance::where('batch_id', $b->id)->count());
        // Step 2 cannot complete until the cleaning checklist is done.
        $this->complete($b, 2, ['completed' => $this->op->id], ['staff' => [$this->pOp->id]])->assertSessionHasErrors('gate');
    }
}
