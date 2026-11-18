<?php

namespace Tests\Feature\Phase1;

use App\Models\ChecklistInstance;
use App\Models\ChecklistTemplate;
use App\Models\Setting;
use App\Services\ChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class ChecklistsM3Test extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
        $this->tz = config('pbp.site_timezone');
    }

    private function at(string $local): void
    {
        $this->travelTo(Carbon::parse($local, $this->tz));
    }

    public function test_list_page_shows_due_and_first_day_is_not_overdue(): void
    {
        $this->at('2026-11-18 23:00');   // switched on late in the evening
        $this->actingAs($this->op)->get('/checklists')->assertOk()->assertSee('Production room cleaning')->assertSee('Scale check with test weight');
        $first = ChecklistInstance::whereHas('template', fn ($q) => $q->where('code', 'daily_room_clean'))->firstOrFail();
        $this->assertNull($first->overdue_at, 'The very first daily list is not marked late');
        $this->assertSame('due', $first->displayStatus());

        // Next day: due 10:00, late 2 h after.
        $this->at('2026-11-19 09:00');
        app(ChecklistService::class)->generate();
        $next = ChecklistInstance::whereHas('template', fn ($q) => $q->where('code', 'daily_room_clean'))->where('period_key', '2026-11-19')->firstOrFail();
        $this->assertSame('due', $next->displayStatus());
        $this->at('2026-11-19 12:30');
        $this->assertSame('overdue', $next->fresh()->displayStatus());
        app(ChecklistService::class)->generate();
        $this->assertSame('overdue', $next->fresh()->status);
        $this->actingAs($this->op)->get('/checklists')->assertOk()->assertSee('Overdue');
        $this->actingAs($this->op)->get('/dashboard')->assertOk()->assertSee('Checklists overdue');
    }

    public function test_weekly_and_monthly_periods(): void
    {
        $this->at('2026-11-18 09:00'); // Wednesday
        app(ChecklistService::class)->generate();
        $w = ChecklistInstance::whereHas('template', fn ($q) => $q->where('code', 'weekly_deep_clean'))->firstOrFail();
        $this->assertSame('2026-W47', $w->period_key);
        $m = ChecklistInstance::whereHas('template', fn ($q) => $q->where('code', 'monthly_security_test'))->firstOrFail();
        $this->assertSame('2026-11', $m->period_key);
        $this->at('2026-11-25 09:00');
        app(ChecklistService::class)->generate();
        $this->assertTrue(ChecklistInstance::whereHas('template', fn ($q) => $q->where('code', 'weekly_deep_clean'))->where('period_key', '2026-W48')->exists());
        $this->assertSame(1, ChecklistInstance::whereHas('template', fn ($q) => $q->where('code', 'monthly_security_test'))->count());
    }

    public function test_fill_in_page_renders_and_signs(): void
    {
        $this->at('2026-11-18 09:00');
        app(ChecklistService::class)->generate();
        $i = ChecklistInstance::whereHas('template', fn ($q) => $q->where('code', 'scale_check_daily'))->firstOrFail();
        $this->actingAs($this->op)->get("/checklists/{$i->id}")->assertOk()->assertSee('Sign as complete')->assertDontSee('open a deviation');
        $first = $i->items()->with('templateItem')->get()->first(fn ($it) => $it->templateItem->response_type === 'pass_fail');
        if ($first) {
            $this->actingAs($this->op)->post("/checklists/{$i->id}/answers", ['items' => [$first->id => ['response' => 'fail', 'note' => 'Weight off']]]);
            $this->actingAs($this->op)->get("/checklists/{$i->id}")->assertSee('failed: open a deviation');
        }
        $this->actingAs($this->op)->post("/checklists/{$i->id}/answers", ['items' => $this->answers($i)])->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $i->fresh()->status);
        $this->actingAs($this->op)->post("/checklists/{$i->id}/complete", ['signer_id' => $this->op->id, 'performed_by_person_id' => $this->pOp->id])->assertSessionHasNoErrors();
        $this->actingAs($this->leo)->post("/checklists/{$i->id}/verify", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        $this->assertTrue(ChecklistService::completedToday('scale_check_daily'));
        $this->actingAs($this->viewer)->post("/checklists/{$i->id}/answers", ['items' => []])->assertForbidden();
    }

    public function test_templates_are_versioned_and_old_checklists_keep_their_version(): void
    {
        $this->at('2026-11-18 09:00');
        $this->actingAs($this->op)->get('/checklists/templates/new')->assertForbidden();
        $this->actingAs($this->leo)->get('/checklists/templates/new')->assertOk();
        $this->actingAs($this->leo)->post('/checklists/templates', ['code' => 'daily_restroom', 'name' => 'Restroom check', 'category' => 'cleaning', 'frequency' => 'daily',
            'due_time' => '09:30', 'grace_hours' => 3, 'scope_type' => 'none', 'completed_by_role' => 'operator',
            'items' => [['prompt' => 'Soap stocked', 'response_type' => 'yes_no'], ['prompt' => '', 'response_type' => 'pass_fail']]])->assertSessionHasNoErrors()->assertRedirect();
        $t = ChecklistTemplate::where('code', 'daily_restroom')->firstOrFail();
        $this->assertSame(1, $t->currentVersion->version_no);
        $this->assertSame(1, $t->currentVersion->items()->count());
        app(ChecklistService::class)->generate();
        $old = ChecklistInstance::where('template_id', $t->id)->firstOrFail();

        $payload = ['name' => 'Restroom check', 'category' => 'cleaning', 'frequency' => 'daily', 'due_time' => '09:30', 'grace_hours' => 3, 'scope_type' => 'none',
            'completed_by_role' => 'operator', 'active' => 1, 'change_note' => 'Added towels',
            'items' => [['prompt' => 'Soap stocked', 'response_type' => 'yes_no'], ['prompt' => 'Towels stocked', 'response_type' => 'yes_no']]];
        $this->actingAs($this->leo)->put("/checklists/templates/{$t->id}", $payload)->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame(2, $t->currentVersion->version_no);
        $this->assertSame(1, $old->fresh()->items()->count(), 'An existing checklist keeps its version');
        $this->assertSame(1, $old->fresh()->version->version_no);

        // Saving without item changes does not make a version.
        $this->actingAs($this->leo)->put("/checklists/templates/{$t->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, $t->fresh()->currentVersion->version_no);
        $this->actingAs($this->leo)->get("/checklists/templates/{$t->id}")->assertOk()->assertSee('Version history')->assertSee('Added towels');

        // Tomorrow's uses v2; switching it off stops new ones.
        $this->at('2026-11-19 09:00');
        app(ChecklistService::class)->generate();
        $new = ChecklistInstance::where('template_id', $t->id)->where('period_key', '2026-11-19')->firstOrFail();
        $this->assertSame(2, $new->items()->count());
        $this->actingAs($this->leo)->put("/checklists/templates/{$t->id}", ['active' => 0] + $payload)->assertSessionHasNoErrors();
        $this->at('2026-11-20 09:00');
        app(ChecklistService::class)->generate();
        $this->assertFalse(ChecklistInstance::where('template_id', $t->id)->where('period_key', '2026-11-20')->exists());

        // A template needs at least one item.
        $this->actingAs($this->leo)->put("/checklists/templates/{$t->id}", ['items' => [['prompt' => '']]] + $payload)->assertSessionHasErrors('gate');
    }

    public function test_step3_needs_todays_scale_check_when_checklists_are_on(): void
    {
        $this->at('2026-11-18 09:00');
        Setting::put('gate_require_daily_scale_check', true);
        $b = $this->startBatch();
        $this->complete($b, 1, ['issued' => $this->leo->id])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/cleaning");
        $chk = ChecklistInstance::where('batch_id', $b->id)->firstOrFail();
        $this->actingAs($this->op)->post("/checklists/{$chk->id}/complete", ['items' => $this->answers($chk), 'signer_id' => $this->op->id, 'performed_by_person_id' => $this->pOp->id]);
        if ($chk->fresh()->status === 'awaiting_verification') {
            $this->actingAs($this->leo)->post("/checklists/{$chk->id}/verify", ['signer_id' => $this->leo->id]);
        }
        $this->complete($b, 2, ['completed' => $this->op->id], ['staff' => [$this->pOp->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/equipment", ['equipment_id' => $this->scale->id, 'condition_ok' => 1]);
        $step3 = fn () => $this->complete($b, 3, ['performed' => $this->op->id, 'verified' => $this->leo->id], ['staff' => [$this->pOp->id]]);

        $step3()->assertSessionHasErrors(['gate' => "Today's scale check for SC-01 (Checklists) has not been completed yet."]);
        $this->actingAs($this->op)->get("/production/{$b->batch_number}")->assertSee('scale check for SC-01');

        // With the Checklists tab off, the requirement is off too.
        config(['pbp.modules' => 'production,receiving']);
        $this->assertNotContains("Today's scale check for SC-01 (Checklists) has not been completed yet.", app(\App\Services\ProductionWorkflow::class)->gateProblems($b->fresh(), 3));
        config(['pbp.modules' => 'production,receiving,checklists,batchlog']);

        app(ChecklistService::class)->generate();
        $sc = ChecklistInstance::whereHas('template', fn ($q) => $q->where('code', 'scale_check_daily'))->firstOrFail();
        $this->actingAs($this->op)->post("/checklists/{$sc->id}/complete", ['items' => $this->answers($sc), 'signer_id' => $this->op->id, 'performed_by_person_id' => $this->pOp->id])->assertSessionHasNoErrors();
        $this->actingAs($this->leo)->post("/checklists/{$sc->id}/verify", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        $step3()->assertSessionHasNoErrors();
        $this->assertSame(4, $b->fresh()->current_step);
    }

    public function test_on_demand_checklist_can_be_started(): void
    {
        $t = ChecklistTemplate::where('frequency', 'on_demand')->where('code', '!=', 'changeover_clean')->first()
            ?? tap(ChecklistTemplate::where('code', 'changeover_clean')->firstOrFail()->replicate(['current_version_id']), fn ($x) => $x->fill(['code' => 'spill', 'name' => 'Spill clean-up'])->save());
        if (! $t->current_version_id) {
            $t->update(['current_version_id' => ChecklistTemplate::where('code', 'changeover_clean')->value('current_version_id')]);
        }
        $this->actingAs($this->op)->post("/checklists/start/{$t->id}")->assertRedirect();
        $this->assertTrue(ChecklistInstance::where('template_id', $t->id)->exists());
        $this->actingAs($this->viewer)->post("/checklists/start/{$t->id}")->assertForbidden();
    }
}
