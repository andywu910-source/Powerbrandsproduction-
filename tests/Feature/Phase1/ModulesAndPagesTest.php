<?php

namespace Tests\Feature\Phase1;

use App\Models\ChecklistInstance;
use App\Models\Deviation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class ModulesAndPagesTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
    }

    public function test_every_phase1_page_renders(): void
    {
        $b = $this->startBatch();
        $this->actingAs($this->op)->post('/deviations', ['batch_id' => $b->id, 'title' => 'Spill', 'description' => 'Small spill', 'signer_id' => $this->op->id]);
        $dev = Deviation::firstOrFail();
        $pqp = $this->mmp->pqp;
        $urls = ['/dashboard', '/production', "/production?product_id={$this->product->id}", "/production/{$b->batch_number}", '/receiving', '/receiving/new',
            "/receiving/{$this->flowerLot->id}", '/checklists', '/checklists/templates/new', '/batch-log', '/deviations', "/deviations/{$dev->id}", '/setup', '/setup/plans',
            "/setup/plans/mmp/{$this->mmp->id}", "/setup/plans/pqp/{$pqp->id}", '/setup/settings', '/setup/process', '/soon/labels'];
        foreach (['brands', 'products', 'ingredients', 'suppliers', 'rooms', 'equipment', 'people', 'training'] as $res) {
            $urls[] = "/setup/{$res}";
        }
        foreach ($urls as $u) {
            $this->actingAs($this->andy)->get($u)->assertOk();
        }
        foreach (['/production', "/production/{$b->batch_number}", '/receiving', '/dashboard'] as $u) {
            $this->actingAs($this->viewer)->get($u)->assertOk();
        }
    }

    public function test_draft_mmp_editor_renders_blank_rows(): void
    {
        $this->actingAs($this->andy)->post("/setup/plans/mmp/{$this->mmp->id}/version")->assertSessionHasNoErrors();
        $draft = \App\Models\Mmp::where('status', 'draft')->latest('id')->firstOrFail();
        $this->actingAs($this->andy)->get("/setup/plans/mmp/{$draft->id}")->assertOk()->assertSee('Save recipe draft')->assertSee('Approve recipe');
    }

    public function test_production_bundle_modules_hide_checklists_and_batch_log(): void
    {
        config(['pbp.modules' => 'production,receiving']);
        $b = $this->startBatch();
        $this->actingAs($this->op)->get('/checklists')->assertNotFound();
        $this->actingAs($this->op)->get('/batch-log')->assertNotFound();
        $this->actingAs($this->op)->get('/production')->assertOk();
        $this->actingAs($this->op)->get('/receiving')->assertOk();
        $this->actingAs($this->op)->get('/dashboard')->assertOk()->assertSee('Soon');
        // Step 2 cleaning still works (it uses the checklist pages directly).
        $this->complete($b, 1, ['issued' => $this->leo->id])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/cleaning")->assertRedirect();
        $chk = ChecklistInstance::where('batch_id', $b->id)->firstOrFail();
        $this->actingAs($this->op)->get("/checklists/{$chk->id}")->assertOk();
    }

    public function test_settings_save_and_pattern_preview(): void
    {
        $this->actingAs($this->andy)->postJson('/setup/settings/preview', ['pattern' => '{BRAND}{PRODUCT}{YYMMDD}{SUFFIX}'])->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($this->andy)->postJson('/setup/settings/preview', ['pattern' => '{BRAND}'])->assertOk()->assertJson(['ok' => false]);
        $this->actingAs($this->op)->get('/setup/settings')->assertForbidden();
    }
}
