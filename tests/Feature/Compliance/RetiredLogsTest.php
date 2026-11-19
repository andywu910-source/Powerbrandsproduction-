<?php

namespace Tests\Feature\Compliance;

use App\Models\AuditLog;
use App\Models\ChecklistInstance;
use App\Models\ChecklistTemplate;
use App\Models\Deviation;
use App\Models\LogDefinition;
use App\Models\LogEntry;
use App\Models\LogEntryLink;
use App\Models\Setting;
use App\Services\ChecklistService;
use App\Services\ProductionWorkflow;
use App\Support\LogHub;
use App\Support\Retirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

/** Nov 19 (Andy): LBL, WST, CAPA retired from the hub; CAPA merged into Deviations; duplicate Checklists retired. */
class RetiredLogsTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private const UID = '1A4060300003B11000012345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,logs']);
    }

    private function def(string $code): LogDefinition
    {
        return LogDefinition::where('code', $code)->firstOrFail();
    }

    public function test_three_logs_are_retired_but_kept(): void
    {
        $this->assertSame(48, LogDefinition::count(), 'nothing deleted');
        $this->assertSame(42, LogDefinition::where('active', true)->count());
        $this->assertSame(['3-B', 'BAL', 'CAPA', 'LBL', 'VIS', 'WST'], LogDefinition::where('active', false)->orderBy('code')->pluck('code')->all());
        LogHub::syncDefinitions();   // re-running keeps them retired
        $this->assertSame(42, LogDefinition::where('active', true)->count());
        $this->assertNotEmpty($this->def('CAPA')->fields, 'fields stay so old entries still display');

        $hub = $this->actingAs($this->andy)->get('/logs')->assertOk()->getContent();
        foreach (['Label Print Log', 'Waste Log (non-cannabis)', 'CAPA Log', '/logs/lbl', '/logs/wst', '/logs/capa'] as $gone) {
            $this->assertStringNotContainsString($gone, $hub);
        }
        $this->assertStringContainsString('Destruction Log', $hub);
        $this->assertStringContainsString('Complaint Log', $hub);
        $board = LogHub::board(LogHub::visible($this->andy));
        $this->assertSame(42, $board['rows']->count(), 'due strip counts only live logs');
        $this->assertFalse($board['rows']->contains(fn ($r) => in_array($r['def']->code, ['LBL', 'WST', 'CAPA'], true)));
    }

    public function test_retired_logs_take_no_entries_and_old_ones_stay_readable_for_managers(): void
    {
        $old = LogEntry::create(['log_definition_id' => $this->def('CAPA')->id, 'data' => ['date_opened' => '2026-11-10', 'source' => 'Complaint', 'problem_description' => 'Old CAPA'],
            'occurred_on' => '2026-11-10', 'entered_by_user_id' => $this->op->id]);

        $this->actingAs($this->op)->get('/logs/capa')->assertNotFound();
        $this->actingAs($this->op)->post('/logs/wst', ['f' => ['date' => '2026-11-19']])->assertNotFound();
        $this->actingAs($this->andy)->post('/logs/capa', ['f' => ['date_opened' => '2026-11-19']])->assertNotFound();
        $this->actingAs($this->andy)->post("/logs/entries/{$old->id}/verify")->assertNotFound();

        $page = $this->actingAs($this->leo)->get('/logs/capa?entry='.$old->id)->assertOk()->getContent();
        $this->assertStringContainsString('id="lgRetired"', $page);
        $this->assertStringContainsString('Merged into the Deviations tab', $page);
        $this->assertStringContainsString('Old CAPA', $page);
        $this->assertStringNotContainsString('id="lgForm"', $page);
        $this->assertStringNotContainsString('id="lgVerify"', $page);
        $this->assertStringNotContainsString('Void this entry', $page);
        $this->assertSame(0, LogEntry::pendingVerification()->whereIn('log_definition_id', LogHub::visible($this->andy)->pluck('id'))->count(), 'queue skips retired logs');
    }

    public function test_related_logs_skip_retired_logs(): void
    {
        $lbl = LogEntry::create(['log_definition_id' => $this->def('LBL')->id, 'data' => ['date' => '2026-11-10', 'batch_no' => 'PB-1'], 'occurred_on' => '2026-11-10', 'entered_by_user_id' => $this->op->id]);
        LogEntryLink::create(['log_entry_id' => $lbl->id, 'kind' => 'batch', 'value' => 'PB-1', 'field_key' => 'batch_no']);
        $this->actingAs($this->op)->get('/logs/related?kind=batch&value=PB-1')->assertOk()->assertDontSee('Label Print Log');
    }

    public function test_destruction_log_has_a_waste_type_and_covers_chemical_waste(): void
    {
        $f = collect($this->def('1-A')->fields)->keyBy('key');
        $this->assertSame(['Cannabis', 'Chemical / hazardous', 'Other'], $f['waste_type']['options']);
        $this->assertTrue($f['waste_type']['req']);
        $this->assertSame(['waste_type', 'Cannabis'], $f['metrc_tag']['req_if']);

        $base = ['date' => '2026-11-19', 'item' => 'Spent isopropanol', 'weight' => '2', 'unit' => 'kg', 'reason' => 'Used solvent',
            'method' => 'Licensed waste hauler', 'performed_by' => 'Sae', 'witness' => 'Maria Operator'];
        $this->actingAs($this->op)->post('/logs/1-a', ['f' => $base])->assertSessionHasErrors('f.waste_type');
        $this->actingAs($this->op)->post('/logs/1-a', ['f' => $base + ['waste_type' => 'Cannabis']])
            ->assertSessionHasErrors(['f.metrc_tag' => 'Metrc tag is required when Waste type is Cannabis.']);
        $this->actingAs($this->op)->post('/logs/1-a', ['f' => $base + ['waste_type' => 'Chemical / hazardous']])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post('/logs/1-a', ['f' => $base + ['waste_type' => 'Cannabis', 'metrc_tag' => self::UID, 'item' => 'Trim']])->assertSessionHasNoErrors();
        $this->assertSame(['Chemical / hazardous', 'Cannabis'], LogEntry::orderBy('id')->get()->map(fn ($e) => $e->data['waste_type'])->all());
        $this->actingAs($this->op)->get('/logs/1-a')->assertSee('(required for cannabis)');
    }

    public function test_deviations_take_capa_fields_and_complaints_point_there(): void
    {
        // a complaint entry offers "open a deviation" pre-filled with the source
        $this->actingAs($this->op)->post('/logs/cmp', ['f' => ['date_received' => '2026-11-19', 'complainant_source' => 'Dispensary', 'complaint_description' => 'Loose cones']])
            ->assertSessionHasNoErrors();
        $cmp = LogEntry::latest('id')->first();
        $page = $this->actingAs($this->op)->get('/logs/cmp?entry='.$cmp->id)->getContent();
        $this->assertStringContainsString('id="lgCmpDeviation"', $page);
        $this->assertStringContainsString(e(route('deviations.index', ['new' => 1, 'origin' => 'complaint', 'origin_ref' => 'CMP entry #'.$cmp->id])), $page);
        $form = $this->actingAs($this->op)->get('/deviations?new=1&origin=complaint&origin_ref=CMP+entry+%23'.$cmp->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<option value="complaint"\s+selected#', $form);
        $this->assertStringContainsString('value="CMP entry #'.$cmp->id.'"', $form);
        $this->assertStringNotContainsString('id="devNew"', $this->actingAs($this->viewer)->get('/deviations')->getContent());

        $this->actingAs($this->op)->post('/deviations', ['origin' => 'complaint', 'origin_ref' => 'CMP entry #'.$cmp->id, 'title' => 'Loose cones',
            'description' => 'Customer found loose cones', 'signer_id' => $this->op->id])->assertSessionHasNoErrors();
        $d = Deviation::latest('id')->firstOrFail();
        $this->assertSame(['complaint', 'CMP entry #'.$cmp->id, null], [$d->origin, $d->origin_ref, $d->batch_id]);
        $this->actingAs($this->op)->post('/deviations', ['origin' => 'nonsense', 'title' => 'x', 'description' => 'y', 'signer_id' => $this->op->id])->assertSessionHasErrors('origin');
        $this->actingAs($this->op)->post('/deviations', ['batch_number' => 'NOPE', 'title' => 'x', 'description' => 'y', 'signer_id' => $this->op->id])->assertSessionHasErrors('batch_number');

        $this->actingAs($this->leo)->post("/deviations/{$d->id}/close", ['root_cause' => 'Worn crimper', 'corrective_action' => 'Re-crimped lot',
            'preventive_action' => 'Weekly crimper check', 'effectiveness_check' => 'No repeat in 30 days', 'disposition' => 'Released', 'signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        $d->refresh();
        $this->assertSame(['closed', 'Weekly crimper check', 'No repeat in 30 days'], [$d->status, $d->preventive_action, $d->effectiveness_check]);
        $show = $this->actingAs($this->op)->get("/deviations/{$d->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Complaint · CMP entry #'.$cmp->id, $show);
        $this->assertStringContainsString('<b>Preventive action:</b> Weekly crimper check', $show);
        $this->assertStringContainsString('<b>Effectiveness check:</b> No repeat in 30 days', $show);
        $this->assertContains('effectiveness_check', $d->correctable, 'the effectiveness check can be added later as a correction');
        $this->actingAs($this->op)->get('/deviations?status=all')->assertSee('Complaint');
    }

    public function test_duplicate_checklists_are_retired_and_the_rest_kept(): void
    {
        $svc = app(ChecklistService::class);
        $svc->generate();
        $openRoom = ChecklistInstance::whereHas('template', fn ($q) => $q->where('code', 'daily_room_clean'))->firstOrFail();
        $sec = ChecklistInstance::whereHas('template', fn ($q) => $q->where('code', 'monthly_security_test'))->firstOrFail();
        $this->actingAs($this->op)->post("/checklists/{$sec->id}/complete", ['items' => $this->answers($sec), 'signer_id' => $this->op->id, 'performed_by_person_id' => $this->pOp->id])->assertSessionHasNoErrors();
        $this->assertSame('awaiting_verification', $sec->fresh()->status);
        $before = ChecklistInstance::count();

        $this->actingAs($this->andy);
        $this->assertSame(5, Retirements::applyChecklists());
        $this->assertSame(0, Retirements::applyChecklists(), 'safe to re-run');
        $this->assertSame(['daily_room_clean', 'monthly_calibration_review', 'monthly_security_test', 'scale_check_daily', 'weekly_deep_clean'],
            ChecklistTemplate::where('active', false)->orderBy('code')->pluck('code')->all());
        $this->assertSame(['changeover_clean', 'daily_security_walk', 'weekly_equipment'], ChecklistTemplate::where('active', true)->orderBy('code')->pluck('code')->all());
        $this->assertSame(5, AuditLog::where('action', 'retire')->where('record_type', 'checklist_template')->count());
        $this->assertSame($before, ChecklistInstance::count(), 'no checklist deleted');

        // nothing new is made for retired ones; the open one can't be filled in, the finished one can still be verified
        Carbon::setTestNow(now()->addDay());
        $svc->generate();
        $this->assertSame(1, ChecklistInstance::whereHas('template', fn ($q) => $q->where('code', 'daily_room_clean'))->count());
        Carbon::setTestNow();
        $list = $this->actingAs($this->op)->get('/checklists')->assertOk()->getContent();
        $this->assertStringNotContainsString(route('checklists.show', $openRoom), $list, 'open retired checklist hidden from the to-do list');
        $this->assertStringContainsString(route('checklists.show', $sec), $list, 'awaiting verification stays so a supervisor can close it out');
        $this->assertStringContainsString('Retired: use', $list);
        $this->actingAs($this->op)->get("/checklists/{$openRoom->id}")->assertOk()->assertSee('This checklist was retired');
        $this->actingAs($this->op)->post("/checklists/{$openRoom->id}/complete", ['items' => $this->answers($openRoom), 'signer_id' => $this->op->id, 'performed_by_person_id' => $this->pOp->id])
            ->assertSessionHasErrors(['gate' => 'This checklist was retired. Record it in the Compliance hub instead (CLN).']);
        $this->actingAs($this->leo)->post("/checklists/{$sec->id}/verify", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        $this->assertSame('completed', $sec->fresh()->status);
        $this->assertStringNotContainsString(route('checklists.show', $openRoom), $this->actingAs($this->op)->get('/dashboard')->getContent());

        // the Checklists tab stays (two non-duplicate checklists are kept)
        $this->assertStringContainsString('href="'.route('checklists.index').'"', $this->actingAs($this->op)->get('/dashboard')->getContent());
    }

    public function test_no_daily_balance_check_gate_once_bal_is_retired_too(): void
    {
        Setting::put('gate_require_daily_scale_check', true);
        Setting::flush();
        $this->actingAs($this->andy);
        Retirements::applyChecklists();
        $b = $this->startBatch();
        $this->complete($b, 1, ['issued' => $this->leo->id])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/cleaning")->assertRedirect();   // changeover clean still works (kept)
        $chk = ChecklistInstance::where('batch_id', $b->id)->firstOrFail();
        $this->actingAs($this->op)->post("/checklists/{$chk->id}/complete", ['items' => $this->answers($chk), 'signer_id' => $this->op->id, 'performed_by_person_id' => $this->pOp->id])->assertSessionHasNoErrors();
        if ($chk->fresh()->status === 'awaiting_verification') {
            $this->actingAs($this->leo)->post("/checklists/{$chk->id}/verify", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        }
        $this->complete($b, 2, ['completed' => $this->op->id], ['staff' => [$this->pOp->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/equipment", ['equipment_id' => $this->scale->id, 'condition_ok' => 1])->assertSessionHasNoErrors();
        $problems = implode(' | ', app(ProductionWorkflow::class)->gateProblems($b->fresh(), 3));
        $this->assertStringNotContainsStringIgnoringCase('balance check', $problems);
        $this->assertStringNotContainsStringIgnoringCase('scale check', $problems, 'the old Checklists scale check does not come back');
        $this->assertFalse(ChecklistTemplate::where('code', 'scale_check_daily')->value('active'));
        $this->complete($b, 3, ['performed' => $this->op->id, 'verified' => $this->leo->id], ['staff' => [$this->pOp->id]])->assertSessionHasNoErrors();

        // Scale register problems still block (calibration overdue).
        $this->scale->update(['calibration_due_on' => now()->subDay()->toDateString()]);
        $this->assertNotEmpty(array_filter(app(ProductionWorkflow::class)->gateProblems($b->fresh(), 3), fn ($p) => str_contains($p, 'SC-01')));
    }
}
