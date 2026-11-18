<?php

namespace Tests\Support;

use App\Models\Batch;
use App\Models\Brand;
use App\Models\ChecklistInstance;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\MaterialLot;
use App\Models\Mmp;
use App\Models\MmpLine;
use App\Models\MmpStep;
use App\Models\Person;
use App\Models\Pqp;
use App\Models\Product;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Services\Inventory\LotLedger;
use App\Services\PublicId;
use Database\Seeders\StarterDataSeeder;

/** Builds a small but complete plant: users, people, codes, an approved recipe and released lots. */
trait Phase1Fixture
{
    protected User $andy, $leo, $jenny, $sae, $op, $viewer;
    protected Person $pSae, $pOp, $pJack;
    protected Brand $brand;
    protected Product $product;
    protected Mmp $mmp;
    protected Ingredient $flower, $cones;
    protected MaterialLot $flowerLot, $conesLot;
    protected Equipment $scale;
    protected Room $room;

    protected function buildPlant(): void
    {
        config(['pbp.modules' => 'production,receiving,checklists,batchlog']);
        $this->andy = User::factory()->admin()->create(['name' => 'Andy Wu']);
        $this->leo = User::factory()->supervisor()->create(['name' => 'Leo']);
        $this->jenny = User::factory()->supervisor()->create(['name' => 'Jenny']);
        $this->sae = User::factory()->create(['name' => 'Sae']);
        $this->op = User::factory()->create(['name' => 'Maria Operator']);
        $this->viewer = User::factory()->create(['name' => 'Vic Viewer', 'role' => User::ROLE_VIEWER]);
        (new StarterDataSeeder)->run();
        Setting::put('gate_require_daily_scale_check', false);
        Setting::flush();

        $this->pSae = Person::where('full_name', 'Sae')->firstOrFail();
        $this->pJack = Person::where('full_name', 'Jack')->firstOrFail();
        $this->pOp = Person::create(['full_name' => 'Maria Operator', 'initials' => 'MO', 'user_id' => $this->op->id, 'active' => true]);
        foreach (Person::where('no_documentation', false)->get() as $p) {
            foreach (['rwt', 'task'] as $cat) {
                TrainingRecord::create(['person_id' => $p->id, 'category' => $cat, 'topic' => strtoupper($cat), 'completed_on' => now()->subMonth()->toDateString(), 'expires_on' => now()->addYear()->toDateString()]);
            }
        }

        $this->room = Room::create(['room_no' => '101', 'name' => 'Preroll room', 'active' => true]);
        $this->scale = Equipment::create(['equip_id' => 'SC-01', 'name' => 'Bench scale', 'is_scale' => true, 'wm_seal_ref' => 'NYS-123',
            'calibration_due_on' => now()->addMonth()->toDateString(), 'active' => true,
            'last_inspected_on' => now()->subMonth()->toDateString(), 'inspection_due_on' => now()->addMonths(11)->toDateString()]);
        $this->brand = Brand::create(['name' => 'Stiiizy', 'short_code' => 'ST']);
        $this->product = Product::create(['code' => 'st-5-blue-razz', 'name' => 'Blue Razz 5pk', 'brand_id' => $this->brand->id, 'short_code' => '5BR',
            'family' => 'preroll', 'unit_net_qty' => 0.5, 'unit_net_uom' => 'g']);

        $supplier = Supplier::create(['name' => 'Farm Co', 'approved' => true, 'active' => true]);
        $this->flower = Ingredient::create(['name' => 'Ground flower', 'kind' => 'cannabis_active', 'unit' => 'g', 'is_cannabis' => true, 'requires_coa' => true]);
        $this->cones = Ingredient::create(['name' => 'Cones', 'kind' => 'hardware', 'unit' => 'ea']);
        $this->flowerLot = $this->releasedLot($supplier, $this->flower, 1000, 'g');
        $this->conesLot = $this->releasedLot($supplier, $this->cones, 500, 'ea');

        $pqp = Pqp::create(['public_id' => PublicId::next('PQP'), 'product_type' => 'preroll', 'version' => 1, 'title' => 'Preroll plan', 'status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->leo->id]);
        $this->mmp = Mmp::create(['public_id' => PublicId::next('MMP'), 'product_id' => $this->product->id, 'version' => 1, 'pqp_id' => $pqp->id, 'title' => 'Blue Razz recipe',
            'basis_units' => 100, 'unit_fill_mass_g' => 0.5, 'yield_min_pct' => 95, 'yield_max_pct' => 102, 'status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->leo->id]);
        MmpLine::create(['mmp_id' => $this->mmp->id, 'line_no' => 1, 'ingredient_id' => $this->flower->id, 'line_type' => 'cannabis_active', 'qty_per_basis' => 50, 'unit' => 'g', 'scale_mode' => 'by_mass']);
        MmpLine::create(['mmp_id' => $this->mmp->id, 'line_no' => 2, 'ingredient_id' => $this->cones->id, 'line_type' => 'hardware', 'qty_per_basis' => 100, 'unit' => 'ea', 'scale_mode' => 'by_count']);
        MmpStep::create(['mmp_id' => $this->mmp->id, 'step_no' => 1, 'stage_code' => 'grinding', 'instruction' => 'Grind', 'parameter' => 'Weight after de-stem', 'unit' => 'g', 'requires_verifier' => true]);
        MmpStep::create(['mmp_id' => $this->mmp->id, 'step_no' => 2, 'stage_code' => 'filling', 'instruction' => 'Fill cones', 'parameter' => 'Fill weight', 'unit' => 'g', 'limit_min' => 0.45, 'limit_max' => 0.55, 'requires_verifier' => true]);
        MmpStep::create(['mmp_id' => $this->mmp->id, 'step_no' => 3, 'stage_code' => 'packaging', 'instruction' => 'Pack', 'requires_verifier' => false]);
    }

    protected function releasedLot(Supplier $s, Ingredient $i, float $qty, string $unit): MaterialLot
    {
        $lot = MaterialLot::create(['public_id' => PublicId::next('RCV'), 'received_at' => now(), 'supplier_id' => $s->id, 'ingredient_id' => $i->id,
            'received_qty' => $qty, 'unit' => $unit, 'condition_ok' => true, 'seals_ok' => true, 'status' => 'released', 'coa_result' => 'pass', 'potency_pct' => 25]);
        LotLedger::post($lot, 'receipt', $qty, $this->andy, 'Opening balance');

        return $lot->fresh();
    }

    /** Start a batch through the UI as Andy. */
    protected function startBatch(int $units = 100, ?string $number = null): Batch
    {
        static $n = 0;
        $number ??= 'PB-TEST-'.str_pad((string) ++$n, 4, '0', STR_PAD_LEFT);
        $this->actingAs($this->andy)->post('/production', ['product_id' => $this->product->id, 'target_units' => $units, 'room_id' => $this->room->id, 'batch_number' => $number])
            ->assertSessionHasNoErrors()->assertRedirect();

        return Batch::latest('id')->firstOrFail();
    }

    protected function complete(Batch $b, int $step, array $signers, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->andy)->post("/production/{$b->batch_number}/steps/{$step}/complete", ['signers' => $signers] + $extra);
    }

    protected function answers(ChecklistInstance $i): array
    {
        $out = [];
        foreach ($i->items()->with('templateItem')->get() as $it) {
            $t = $it->templateItem;
            $out[$it->id] = ['response' => match ($t->response_type) {
                'pass_fail' => 'pass', 'yes_no' => 'yes',
                'numeric' => (string) ($t->min !== null && $t->max !== null ? ($t->min + $t->max) / 2 : ($t->min ?? $t->max ?? 1)),
                default => 'ok',
            }];
        }

        return $out;
    }

    /** Steps 1–4 done properly. */
    protected function throughStep4(Batch $b): void
    {
        $this->complete($b, 1, ['issued' => $this->leo->id])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/cleaning")->assertRedirect();
        $chk = ChecklistInstance::where('batch_id', $b->id)->latest('id')->firstOrFail();
        $this->actingAs($this->op)->post("/checklists/{$chk->id}/complete", ['items' => $this->answers($chk), 'signer_id' => $this->op->id, 'performed_by_person_id' => $this->pOp->id])->assertSessionHasNoErrors();
        if ($chk->fresh()->status === 'awaiting_verification') {
            $this->actingAs($this->leo)->post("/checklists/{$chk->id}/verify", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        }
        $this->complete($b, 2, ['completed' => $this->op->id], ['staff' => [$this->pOp->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/equipment", ['equipment_id' => $this->scale->id, 'condition_ok' => 1])->assertSessionHasNoErrors();
        $this->complete($b, 3, ['performed' => $this->op->id, 'verified' => $this->leo->id], ['staff' => [$this->pOp->id]])->assertSessionHasNoErrors();
        foreach ([$this->pOp, $this->pSae] as $p) {
            $this->actingAs($this->leo)->post("/production/{$b->batch_number}/people", ['person_id' => $p->id, 'task' => 'filling', 'ppe_ok' => 1])->assertSessionHasNoErrors();
        }
        $this->complete($b, 4, ['approved' => $this->leo->id])->assertSessionHasNoErrors();
    }

    /** Step 5: calculate, confirm, pick, weigh (given actuals), verify. Returns material line ids. */
    protected function batching(Batch $b, float $flowerActual = 50, ?float $conesActual = 100): array
    {
        $bn = $b->batch_number;
        $this->actingAs($this->op)->post("/production/{$bn}/calculate")->assertSessionHasNoErrors();
        $this->actingAs($this->leo)->post("/production/{$bn}/calculate/confirm", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        $lines = $this->mmp->lines()->get();
        $ids = [];
        foreach ([[$lines[0], $this->flowerLot, 50, $flowerActual], [$lines[1], $this->conesLot, 100, $conesActual]] as [$line, $lot, $qty, $actual]) {
            $this->actingAs($this->op)->post("/production/{$bn}/pick", ['mmp_line_id' => $line->id, 'material_lot_id' => $lot->id, 'qty' => $qty])->assertSessionHasNoErrors();
            $ml = \App\Models\BatchMaterialLine::where('batch_id', $b->id)->where('mmp_line_id', $line->id)->whereNull('voided_at')->latest('id')->first();
            $this->actingAs($this->op)->post("/production/{$bn}/lines/{$ml->id}/weigh", ['actual_qty' => $actual, 'scale_equipment_id' => $this->scale->id, 'signer_id' => $this->op->id])->assertSessionHasNoErrors();
            $this->actingAs($this->leo)->post("/production/{$bn}/lines/{$ml->id}/verify", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
            $ids[] = $ml->id;
        }

        return $ids;
    }

    /** Steps 5–8 done properly (after throughStep4). */
    protected function throughStep8(Batch $b): void
    {
        $bn = $b->batch_number;
        $this->batching($b);
        $this->complete($b, 5, ['completed' => $this->leo->id], ['staff' => [$this->pOp->id]])->assertSessionHasNoErrors();
        foreach ($this->mmp->steps()->orderBy('step_no')->get() as $ms) {
            $this->actingAs($this->op)->post("/production/{$bn}/mmp/{$ms->id}", ['actual_value' => $ms->limit_min !== null ? 0.5 : 50, 'signer_id' => $this->op->id, 'staff' => [$this->pOp->id]])->assertSessionHasNoErrors();
            if ($ms->requires_verifier) {
                $rec = \App\Models\BatchMmpStep::where('batch_id', $b->id)->where('mmp_step_id', $ms->id)->firstOrFail();
                $this->actingAs($this->leo)->post("/production/{$bn}/mmp-records/{$rec->id}/verify", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
            }
        }
        $this->complete($b, 6, ['completed' => $this->op->id], ['staff' => [$this->pOp->id]])->assertSessionHasNoErrors();
        $this->complete($b, 7, ['performed' => $this->op->id, 'verified' => $this->leo->id], ['staff' => [$this->pOp->id], 'data' => [
            'label_version' => 'v1', 'units_packed' => 100, 'labels_issued' => 110, 'labels_used' => 100, 'labels_destroyed' => 5, 'labels_returned' => 5]])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$bn}/upload", ['kind' => 'coc', 'file' => \Illuminate\Http\UploadedFile::fake()->create('coc.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
        $this->complete($b, 8, ['sampled' => $this->op->id], ['staff' => [$this->pOp->id], 'data' => [
            'sampled_at' => now()->subHour()->format('Y-m-d H:i'), 'lab_name' => 'Lab A', 'sampler_name' => 'Sam', 'video_id' => 'VID-1', 'retained_qty' => '2', 'retained_location' => 'Shelf R1']])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$bn}/upload", ['kind' => 'coa', 'file' => \Illuminate\Http\UploadedFile::fake()->create('coa.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/production/{$bn}/coa", ['coa_lab' => 'Lab A', 'coa_sample_id' => 'S-1', 'coa_result' => 'pass'])->assertSessionHasNoErrors();
    }
}
