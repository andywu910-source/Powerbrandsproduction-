<?php

namespace Tests\Feature\Phase1;

use App\Models\BatchMaterialLine;
use App\Models\Ingredient;
use App\Models\MaterialLot;
use App\Models\Supplier;
use App\Services\Inventory\InventorySource;
use App\Services\Inventory\LotLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class LotsTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildPlant();
    }

    public function test_stock_can_never_go_negative(): void
    {
        $this->expectException(\App\Exceptions\GateFailed::class);
        LotLedger::post($this->conesLot, 'consume', -501, $this->andy, 'too much');
    }

    public function test_received_lot_is_quarantined_and_needs_two_people_and_qc(): void
    {
        $sup = Supplier::first();
        $this->actingAs($this->op)->post('/receiving', ['supplier_id' => $sup->id, 'ingredient_id' => $this->flower->id, 'qty' => 200, 'unit' => 'g',
            'condition_ok' => 1, 'seals_ok' => 1, 'signer_id' => $this->op->id])->assertSessionHasErrors('gate'); // Metrc ids required
        $this->actingAs($this->op)->post('/receiving', ['supplier_id' => $sup->id, 'ingredient_id' => $this->flower->id, 'qty' => 200, 'unit' => 'g',
            'metrc_manifest_no' => 'M-1', 'metrc_package_uid' => 'UID-1', 'condition_ok' => 1, 'seals_ok' => 1, 'signer_id' => $this->op->id])->assertSessionHasNoErrors();
        $lot = MaterialLot::latest('id')->firstOrFail();
        $this->assertSame('quarantine', $lot->status);
        $this->assertEquals(0, (float) $lot->current_balance);

        // Quarantined lots cannot be picked or listed for batches.
        $this->assertFalse(app(InventorySource::class)->listLots($this->flower)->contains('id', $lot->id));
        $this->actingAs($this->jenny)->post("/receiving/{$lot->id}/release", ['signer_id' => $this->jenny->id])->assertSessionHasErrors('gate');

        $this->actingAs($this->op)->post("/receiving/{$lot->id}/verify", ['signer_id' => $this->op->id])->assertSessionHasErrors('gate');
        $this->actingAs($this->leo)->post("/receiving/{$lot->id}/verify", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/receiving/{$lot->id}/upload", ['kind' => 'coa', 'file' => UploadedFile::fake()->create('coa.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/receiving/{$lot->id}/paperwork", ['potency_pct' => 24.5, 'coa_result' => 'pass', 'signer_id' => $this->op->id])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/receiving/{$lot->id}/release", ['signer_id' => $this->op->id])->assertSessionHasErrors('gate'); // not QC
        $this->actingAs($this->jenny)->post("/receiving/{$lot->id}/release", ['signer_id' => $this->jenny->id])->assertSessionHasNoErrors();
        $lot->refresh();
        $this->assertSame('released', $lot->status);
        $this->assertEquals(200, (float) $lot->current_balance);
        $this->actingAs($this->op)->get("/receiving/{$lot->id}")->assertOk()->assertSee($lot->public_id);
        $this->actingAs($this->op)->get('/receiving')->assertOk()->assertSee($lot->public_id);
    }

    public function test_picks_reserve_stock_and_cannot_exceed_available(): void
    {
        $other = $this->startBatch();
        $b = $this->startBatch();
        $this->assertNotSame($other->batch_number, $b->batch_number);
        $this->throughStep4($b);
        $bn = $b->batch_number;
        $this->actingAs($this->op)->post("/production/{$bn}/calculate");
        $this->actingAs($this->leo)->post("/production/{$bn}/calculate/confirm", ['signer_id' => $this->leo->id]);
        $line = $this->mmp->lines()->first();
        $this->actingAs($this->op)->post("/production/{$bn}/pick", ['mmp_line_id' => $line->id, 'material_lot_id' => $this->flowerLot->id, 'qty' => 60])->assertSessionHasErrors('gate'); // over plan
        $this->actingAs($this->op)->post("/production/{$bn}/pick", ['mmp_line_id' => $line->id, 'material_lot_id' => $this->conesLot->id, 'qty' => 50])->assertSessionHasErrors('gate'); // wrong ingredient
        $this->actingAs($this->op)->post("/production/{$bn}/pick", ['mmp_line_id' => $line->id, 'material_lot_id' => $this->flowerLot->id, 'qty' => 50])->assertSessionHasNoErrors();
        $inv = app(InventorySource::class);
        $this->assertEqualsWithDelta(950, $inv->availableQty($this->flowerLot->fresh()), 0.001);
        $this->assertEqualsWithDelta(1000, (float) $this->flowerLot->fresh()->current_balance, 0.001); // reserved, not consumed

        $ml = BatchMaterialLine::where('batch_id', $b->id)->firstOrFail();
        $this->actingAs($this->op)->post("/production/{$bn}/lines/{$ml->id}/void", ['reason' => 'Wrong bin'])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(1000, $inv->availableQty($this->flowerLot->fresh()), 0.001);
    }

    public function test_quarantined_or_expired_lot_cannot_be_picked(): void
    {
        $b = $this->startBatch();
        $this->throughStep4($b);
        $bn = $b->batch_number;
        $q = MaterialLot::create(['public_id' => 'RCV-Q', 'received_at' => now(), 'supplier_id' => Supplier::first()->id, 'ingredient_id' => $this->flower->id,
            'received_qty' => 100, 'unit' => 'g', 'status' => 'quarantine', 'current_balance' => 0]);
        $this->actingAs($this->op)->post("/production/{$bn}/calculate");
        $this->actingAs($this->leo)->post("/production/{$bn}/calculate/confirm", ['signer_id' => $this->leo->id]);
        $line = $this->mmp->lines()->first();
        $this->actingAs($this->op)->post("/production/{$bn}/pick", ['mmp_line_id' => $line->id, 'material_lot_id' => $q->id, 'qty' => 10])->assertSessionHasErrors('gate');
        $this->flowerLot->update(['expiration_date' => now()->subDay()->toDateString()]);
        $this->actingAs($this->op)->post("/production/{$bn}/pick", ['mmp_line_id' => $line->id, 'material_lot_id' => $this->flowerLot->id, 'qty' => 10])->assertSessionHasErrors('gate');
        $this->assertSame(0, BatchMaterialLine::count());
    }

    public function test_stock_adjustment_needs_reason_and_ledger_is_append_only(): void
    {
        $this->actingAs($this->op)->post("/receiving/{$this->conesLot->id}/adjust", ['type' => 'waste', 'qty' => 5, 'unit' => 'ea', 'reason' => 'Crushed', 'signer_id' => $this->op->id])->assertForbidden();
        $this->actingAs($this->leo)->post("/receiving/{$this->conesLot->id}/adjust", ['type' => 'waste', 'qty' => 5, 'unit' => 'ea', 'reason' => '', 'signer_id' => $this->leo->id])->assertSessionHasErrors('reason');
        $this->actingAs($this->leo)->post("/receiving/{$this->conesLot->id}/adjust", ['type' => 'waste', 'qty' => 5, 'unit' => 'ea', 'reason' => 'Crushed', 'signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        $this->actingAs($this->leo)->post("/receiving/{$this->conesLot->id}/adjust", ['type' => 'waste', 'qty' => 9999, 'unit' => 'ea', 'reason' => 'All', 'signer_id' => $this->leo->id])->assertSessionHasErrors('gate');
        $this->assertEquals(495, (float) $this->conesLot->fresh()->current_balance);
        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::table('lot_ledger')->update(['qty_delta' => 0]);
    }
}
