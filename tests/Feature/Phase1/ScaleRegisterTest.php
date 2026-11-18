<?php

namespace Tests\Feature\Phase1;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\ChecklistInstance;
use App\Models\ChecklistTemplate;
use App\Models\Equipment;
use App\Models\ScaleInspection;
use App\Models\Setting;
use App\Services\ChecklistService;
use App\Services\ProductionWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class ScaleRegisterTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
        $this->travelTo(Carbon::parse('2026-11-18 09:00', config('pbp.site_timezone')));
        Storage::fake('local');
    }

    private function scaleData(array $over = []): array
    {
        return $over + ['equip_id' => 'SC-02', 'name' => 'Floor scale', 'serial' => 'OH-998', 'room_id' => $this->room->id, 'location' => 'Bench 2',
            'capacity' => 6000, 'capacity_unit' => 'g', 'division' => 0.1, 'wm_reported_on' => '2026-01-05', 'wm_sealed_on' => '2026-02-01', 'wm_seal_ref' => 'SEAL-77',
            'last_inspected_on' => '2026-02-01', 'calibration_due_on' => '2027-01-01', 'active' => 1, 'notes' => 'Bought Jan 2026'];
    }

    public function test_register_lists_scales_with_flags_and_supervisor_can_add(): void
    {
        $this->actingAs($this->op)->get('/setup/scales')->assertOk()->assertSee('SC-01')->assertDontSee('Add a scale');
        $this->actingAs($this->op)->post('/setup/scales', $this->scaleData())->assertForbidden();
        $this->actingAs($this->viewer)->post('/setup/scales', $this->scaleData())->assertForbidden();

        $this->actingAs($this->leo)->post('/setup/scales', $this->scaleData())->assertSessionHasNoErrors()->assertRedirect();
        $s = Equipment::where('equip_id', 'SC-02')->firstOrFail();
        $this->assertTrue($s->is_scale);
        $this->assertSame('2027-02-01', $s->inspection_due_on->toDateString(), 'Next due defaults to last + 12 months');
        $this->assertSame('ok', $s->inspectionStatus());
        $this->assertSame(6000.0, $s->capacity);
        $this->assertTrue(AuditLog::where('record_type', 'equipment')->where('record_id', $s->id)->where('action', 'create')->exists());

        // Due within 30 days, overdue, missing
        $s->update(['inspection_due_on' => '2026-12-10']);
        $this->assertSame('due_soon', $s->fresh()->inspectionStatus());
        $s->update(['inspection_due_on' => '2026-11-17']);
        $this->assertSame('overdue', $s->fresh()->inspectionStatus());
        $this->actingAs($this->op)->get('/setup/scales')->assertSee('Overdue');
        $this->actingAs($this->op)->get('/dashboard')->assertOk()->assertSee('Scales needing inspection')->assertSee('SC-02 · Floor scale')->assertSee('was due');
        $this->actingAs($this->op)->get('/setup')->assertSee('Scale register')->assertSee('1 overdue');
        $s->update(['inspection_due_on' => null, 'last_inspected_on' => null]);
        $this->assertSame('missing', $s->fresh()->inspectionStatus());
    }

    public function test_due_date_can_not_be_more_than_12_months_after_last_inspection(): void
    {
        $this->actingAs($this->leo)->post('/setup/scales', $this->scaleData(['inspection_due_on' => '2027-03-01']))->assertSessionHasErrors('gate');
        $this->assertFalse(Equipment::where('equip_id', 'SC-02')->exists());
        $this->actingAs($this->leo)->post('/setup/scales', $this->scaleData(['last_inspected_on' => '2026-12-01']))->assertSessionHasErrors('gate');   // future date
        $this->actingAs($this->leo)->post('/setup/scales', $this->scaleData(['inspection_due_on' => '2026-08-01']))->assertSessionHasNoErrors();
        $this->assertSame('2026-08-01', Equipment::where('equip_id', 'SC-02')->first()->inspection_due_on->toDateString());
    }

    public function test_edit_is_audited_and_never_deleted(): void
    {
        $this->actingAs($this->leo)->put("/setup/scales/{$this->scale->id}", $this->scaleData(['equip_id' => 'SC-01', 'name' => 'Bench scale', 'wm_seal_ref' => 'NYS-999',
            'last_inspected_on' => '2026-10-01']))->assertSessionHasNoErrors();
        $s = $this->scale->fresh();
        $this->assertSame('NYS-999', $s->wm_seal_ref);
        $this->assertSame('2027-10-01', $s->inspection_due_on->toDateString(), 'Changing the last inspection moves the default due date');
        $log = AuditLog::where('record_type', 'equipment')->where('record_id', $s->id)->where('action', 'update')->latest('id')->firstOrFail();
        $this->assertSame('NYS-123', $log->details['old']['wm_seal_ref']);
        $this->actingAs($this->op)->get("/setup/scales/{$s->id}")->assertOk()->assertSee('Change history')->assertSee('NYS-123');

        $this->expectException(\App\Exceptions\RecordDeletionForbidden::class);
        $s->delete();
    }

    public function test_recording_an_inspection_updates_the_scale_and_keeps_the_certificate(): void
    {
        $this->scale->update(['inspection_due_on' => '2026-11-01']);   // overdue
        $this->actingAs($this->op)->post("/setup/scales/{$this->scale->id}/inspections", ['kind' => 'wm_inspection', 'inspected_on' => '2026-11-17', 'result' => 'passed_sealed'])->assertForbidden();
        $this->actingAs($this->jenny)->post("/setup/scales/{$this->scale->id}/inspections", [
            'kind' => 'wm_inspection', 'inspected_on' => '2026-11-17', 'inspector' => 'Suffolk County W&M', 'result' => 'passed_sealed', 'seal_number' => 'SEAL-2026-11',
            'certificate' => UploadedFile::fake()->create('cert.pdf', 30, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $s = $this->scale->fresh();
        $this->assertSame('2026-11-17', $s->last_inspected_on->toDateString());
        $this->assertSame('2027-11-17', $s->inspection_due_on->toDateString());
        $this->assertSame('SEAL-2026-11', $s->wm_seal_ref);
        $this->assertSame('2026-11-17', $s->wm_sealed_on->toDateString());
        $ins = ScaleInspection::firstOrFail();
        $this->assertSame(1, Attachment::where('record_type', 'scale_inspection')->where('record_id', $ins->id)->where('kind', 'wm_certificate')->count());
        $this->actingAs($this->op)->get("/setup/scales/{$s->id}")->assertSee('cert.pdf')->assertSee('Passed and sealed');

        // A failed inspection takes the scale out of use.
        $this->actingAs($this->jenny)->post("/setup/scales/{$s->id}/inspections", ['kind' => 'wm_inspection', 'inspected_on' => '2026-11-18', 'result' => 'failed'])->assertSessionHasNoErrors();
        $this->assertFalse($s->fresh()->active);

        // Void keeps the record; the database refuses a delete.
        $this->actingAs($this->jenny)->post("/setup/scale-inspections/{$ins->id}/void", ['reason' => ''])->assertSessionHasErrors('gate');
        $this->actingAs($this->jenny)->post("/setup/scale-inspections/{$ins->id}/void", ['reason' => 'Wrong scale'])->assertSessionHasNoErrors();
        $this->assertNotNull($ins->fresh()->voided_at);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('scale_inspections')->where('id', $ins->id)->delete();
    }

    public function test_step3_refuses_overdue_or_inactive_scales_and_the_setting_switches_it_off(): void
    {
        $b = $this->startBatch();
        $this->complete($b, 1, ['issued' => $this->leo->id])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/cleaning");
        $chk = ChecklistInstance::where('batch_id', $b->id)->firstOrFail();
        $this->actingAs($this->op)->post("/checklists/{$chk->id}/complete", ['items' => $this->answers($chk), 'signer_id' => $this->op->id, 'performed_by_person_id' => $this->pOp->id]);
        if ($chk->fresh()->status === 'awaiting_verification') {
            $this->actingAs($this->leo)->post("/checklists/{$chk->id}/verify", ['signer_id' => $this->leo->id]);
        }
        $this->complete($b, 2, ['completed' => $this->op->id], ['staff' => [$this->pOp->id]])->assertSessionHasNoErrors();

        // Off at deploy; the fixture's SC-01 has a last inspection date, so it switched itself on.
        $this->assertTrue(Setting::get('gate_refuse_uninspected_scales'));
        $this->scale->update(['inspection_due_on' => '2026-11-10']);
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/equipment", ['equipment_id' => $this->scale->id, 'condition_ok' => 1])
            ->assertSessionHasErrors(['gate' => 'SC-01 Weights & Measures inspection was due Nov 10, 2026 (scale register).']);
        $this->scale->update(['inspection_due_on' => null]);
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/equipment", ['equipment_id' => $this->scale->id, 'condition_ok' => 1])->assertSessionHasErrors('gate');

        Setting::put('gate_refuse_uninspected_scales', false);
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/equipment", ['equipment_id' => $this->scale->id, 'condition_ok' => 1])->assertSessionHasNoErrors();

        // Added, then taken out of use: step 3 can't be signed (inactive is always refused).
        Setting::put('gate_refuse_uninspected_scales', true);
        $this->scale->update(['inspection_due_on' => '2027-06-01', 'active' => false]);
        $this->assertContains('SC-01 is not active.', app(ProductionWorkflow::class)->gateProblems($b->fresh(), 3));
        $this->scale->update(['active' => true]);
        $this->complete($b, 3, ['performed' => $this->op->id, 'verified' => $this->leo->id], ['staff' => [$this->pOp->id]])->assertSessionHasNoErrors();
    }

    public function test_daily_scale_check_is_one_per_scale_and_records_which_scale(): void
    {
        $this->assertSame('scale', ChecklistTemplate::where('code', 'scale_check_daily')->value('scope_type'));
        $second = Equipment::create(['equip_id' => 'SC-02', 'name' => 'Floor scale', 'is_scale' => true, 'wm_seal_ref' => 'X', 'calibration_due_on' => '2027-01-01',
            'inspection_due_on' => '2027-01-01', 'active' => true]);
        Equipment::create(['equip_id' => 'SC-OLD', 'name' => 'Old scale', 'is_scale' => true, 'active' => false]);
        Equipment::create(['equip_id' => 'MX-01', 'name' => 'Mixer', 'is_scale' => false, 'active' => true]);
        app(ChecklistService::class)->generate();
        $keys = ChecklistInstance::whereHas('template', fn ($q) => $q->where('code', 'scale_check_daily'))->pluck('scope_key')->sort()->values()->all();
        $this->assertSame(["equipment:{$this->scale->id}", "equipment:{$second->id}"], $keys);

        $i = ChecklistInstance::where('scope_key', "equipment:{$second->id}")->firstOrFail();
        $this->actingAs($this->op)->get("/checklists/{$i->id}")->assertOk()->assertSee('Scale checked')->assertSee('SC-02 · Floor scale');
        $this->actingAs($this->op)->get('/checklists')->assertSee('SC-02 · Floor scale');
        $this->assertFalse(ChecklistService::completedTodayForScale('scale_check_daily', $second->id));
        $this->actingAs($this->op)->post("/checklists/{$i->id}/complete", ['items' => $this->answers($i), 'signer_id' => $this->op->id, 'performed_by_person_id' => $this->pOp->id])->assertSessionHasNoErrors();
        $this->actingAs($this->leo)->post("/checklists/{$i->id}/verify", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        $this->assertTrue(ChecklistService::completedTodayForScale('scale_check_daily', $second->id));
        $this->assertFalse(ChecklistService::completedTodayForScale('scale_check_daily', $this->scale->id));
    }

    public function test_settings_have_the_new_toggles(): void
    {
        $this->actingAs($this->andy)->get('/setup/settings')->assertOk()->assertSee('Refuse scales that are overdue for inspection')->assertSee('Scale inspection every (months)');
    }
}
