<?php

namespace Tests\Feature\Phase1;

use App\Models\Batch;
use App\Models\Deviation;
use App\Models\Revision;
use App\Models\Signature;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class RecordsTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
    }

    public function test_corrections_keep_the_old_value(): void
    {
        $this->actingAs($this->op)->post("/records/material_lot/{$this->flowerLot->id}/correct", ['field' => 'supplier_lot', 'new_value' => 'SL-9', 'reason' => 'Typo', 'initials' => 'MO'])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post("/records/material_lot/{$this->flowerLot->id}/correct", ['field' => 'supplier_lot', 'new_value' => 'SL-10', 'reason' => '', 'initials' => 'MO'])->assertSessionHasErrors('gate');
        $this->actingAs($this->op)->post("/records/material_lot/{$this->flowerLot->id}/correct", ['field' => 'status', 'new_value' => 'released', 'reason' => 'x', 'initials' => 'MO'])->assertSessionHasErrors('gate');
        $rev = Revision::firstOrFail();
        $this->assertSame('supplier_lot', $rev->field);
        $this->assertNull($rev->old_value);
        $this->assertSame('SL-9', $rev->new_value);
        $this->assertSame('SL-9', $this->flowerLot->fresh()->supplier_lot);
        $this->actingAs($this->viewer)->post("/records/material_lot/{$this->flowerLot->id}/correct", ['field' => 'supplier_lot', 'new_value' => 'Z', 'reason' => 'x', 'initials' => 'VV'])->assertForbidden();
    }

    public function test_void_needs_supervisor_and_reason(): void
    {
        $this->actingAs($this->op)->post('/deviations', ['title' => 'Spill', 'description' => 'Small spill', 'signer_id' => $this->op->id]);
        $d = Deviation::firstOrFail();
        $this->actingAs($this->op)->post("/records/deviation/{$d->id}/void", ['reason' => 'dup'])->assertForbidden();
        $this->actingAs($this->leo)->post("/records/deviation/{$d->id}/void", ['reason' => ''])->assertSessionHasErrors();
        $this->actingAs($this->leo)->post("/records/deviation/{$d->id}/void", ['reason' => 'Duplicate'])->assertSessionHasNoErrors();
        $this->assertNotNull($d->fresh()->voided_at);
        $this->assertSame(1, Deviation::count());
        // Lots with stock movements cannot be voided.
        $this->actingAs($this->leo)->post("/records/material_lot/{$this->flowerLot->id}/void", ['reason' => 'x'])->assertSessionHasErrors('gate');
    }

    public function test_database_refuses_deletes_and_signature_edits(): void
    {
        $b = $this->startBatch();
        $this->complete($b, 1, ['issued' => $this->leo->id]);
        foreach ([fn () => Batch::query()->delete(), fn () => DB::table('signatures')->delete(), fn () => DB::table('material_lots')->delete(), fn () => DB::table('users')->delete()] as $f) {
            try {
                $f();
                $this->fail('Delete should be refused');
            } catch (QueryException $e) {
                $this->assertStringContainsString('cannot be deleted', $e->getMessage());
            }
        }
        $this->assertSame(1, Batch::count());
        $this->assertGreaterThan(0, Signature::count());
    }

    public function test_signatures_record_who_and_hash(): void
    {
        $b = $this->startBatch();
        $this->actingAs($this->op)->post("/production/{$b->batch_number}/steps/1/complete", ['signers' => ['issued' => $this->leo->id]])->assertSessionHasNoErrors();
        $s = Signature::where('meaning', 'issued')->firstOrFail();
        $this->assertSame($this->leo->id, $s->user_id);
        $this->assertSame($this->op->id, $s->recorded_by_user_id);
        $this->assertSame(64, strlen($s->record_hash));
    }

    public function test_signing_modes_pin(): void
    {
        \App\Models\Setting::put('signing_auth_mode', 'pin');
        $this->leo->forceFill(['signing_pin_hash' => \Illuminate\Support\Facades\Hash::make('4321')])->save();
        $b = $this->startBatch();
        $this->complete($b, 1, ['issued' => $this->leo->id], ['signing_credential' => [$this->leo->id => '0000']])->assertSessionHasErrors('gate');
        $this->complete($b, 1, ['issued' => $this->leo->id], ['signing_credential' => [$this->leo->id => '4321']])->assertSessionHasNoErrors();
    }
}
