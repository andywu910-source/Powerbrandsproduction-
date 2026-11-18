<?php

namespace Tests\Feature\Phase1;

use App\Models\Batch;
use App\Models\Setting;
use App\Services\BatchNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class BatchNumberTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
    }

    public function test_suggest_button_follows_the_pattern_and_same_day_suffixes(): void
    {
        $this->travelTo(now()->setTimezone(config('pbp.site_timezone'))->setTime(12, 0));
        $ymd = now()->setTimezone(config('pbp.site_timezone'))->format('ymd');
        $this->assertSame("ST5BR{$ymd}", BatchNumberGenerator::peek($this->product));
        $this->actingAs($this->andy)->get("/production?product_id={$this->product->id}")->assertSee('id="bnSuggest" data-value="ST5BR'.$ymd.'"', false);
        $a = $this->startBatch(100, "ST5BR{$ymd}")->batch_number;   // the suggestion, typed / tapped
        $this->assertSame("ST5BR{$ymd}B", BatchNumberGenerator::peek($this->product));
        $this->actingAs($this->andy)->get("/production?product_id={$this->product->id}")->assertSee("ST5BR{$ymd}B");
        $this->startBatch(100, 'MY-OWN-1');
        $this->assertSame("ST5BR{$ymd}B", BatchNumberGenerator::peek($this->product), 'typed numbers do not use up a suggestion');
        $this->assertSame(11, strlen($a));
    }

    public function test_suffix_skips_lookalike_letters(): void
    {
        $seen = [];
        for ($n = 2; $n <= 60; $n++) {
            $s = BatchNumberGenerator::letters($n);
            $this->assertDoesNotMatchRegularExpression('/[IO]/', $s);
            $this->assertNotContains($s, $seen);
            $seen[] = $s;
        }
        $this->assertSame('B', BatchNumberGenerator::letters(2));
        $this->assertSame('H', BatchNumberGenerator::letters(8));
        $this->assertSame('J', BatchNumberGenerator::letters(9));
    }

    public function test_batch_number_is_immutable(): void
    {
        $b = $this->startBatch();
        $this->expectException(\Illuminate\Database\QueryException::class);
        Batch::whereKey($b->id)->update(['batch_number' => 'XX']);
    }

    public function test_pattern_change_does_not_touch_existing_numbers(): void
    {
        $first = $this->startBatch(100, 'FIRST-1')->batch_number;
        Setting::put('batch_number_pattern', '{BRAND}-{PRODUCT}-{YYMMDD}{SEQ2}');
        $this->assertStringStartsWith('ST-5BR-', BatchNumberGenerator::peek($this->product));
        $this->assertSame($first, Batch::orderBy('id')->first()->batch_number);
    }

    public function test_typed_batch_number_is_required_trimmed_any_format_and_unique_in_any_case(): void
    {
        $base = ['product_id' => $this->product->id, 'target_units' => 100, 'room_id' => $this->room->id];
        $this->actingAs($this->leo)->post('/production', $base)->assertSessionHasErrors(['batch_number' => 'Type the batch number.']);
        $this->actingAs($this->leo)->post('/production', $base + ['batch_number' => '   '])->assertSessionHasErrors('batch_number');
        // any format, typed by a supervisor (not only admins), trimmed
        $this->actingAs($this->leo)->post('/production', $base + ['batch_number' => '  bb ny/25 #7  '])->assertSessionHasNoErrors()->assertRedirect();
        $b = Batch::latest('id')->firstOrFail();
        $this->assertSame('bb ny/25 #7', $b->batch_number);
        $this->assertNull($b->batch_number_override_reason);
        $this->assertTrue(\App\Models\AuditLog::where('action', 'batch_number_typed')->where('record_id', $b->id)->exists());
        // duplicate in another case: refused with a clear message, nothing created
        $n = Batch::count();
        $this->actingAs($this->andy)->post('/production', $base + ['batch_number' => 'BB NY/25 #7'])
            ->assertSessionHasErrors(['batch_number' => 'Batch number "BB NY/25 #7" is already in the Batch Log (as "bb ny/25 #7"). Type a different number.']);
        $this->assertSame($n, Batch::count());
        // the server checks again even past the form validation (workflow)
        try {
            app(\App\Services\ProductionWorkflow::class)->start($this->product->fresh(), 10, null, $this->andy, ['batch_number' => 'bb NY/25 #7']);
            $this->fail('duplicate accepted');
        } catch (\App\Exceptions\GateFailed $e) {
            $this->assertStringContainsString('already in the Batch Log', $e->getMessage().implode(' ', $e->reasons ?? []));
        }
        $this->actingAs($this->andy)->post('/production', $base + ['batch_number' => str_repeat('X', 61)])->assertSessionHasErrors('batch_number');
        $this->actingAs($this->andy)->post('/production', $base + ['batch_number' => "A\nB"])->assertSessionHasErrors('batch_number');
        // live check while typing
        $this->actingAs($this->andy)->getJson('/production/batch-number-check?n='.rawurlencode('BB ny/25 #7'))->assertOk()->assertJson(['ok' => false]);
        $this->actingAs($this->andy)->getJson('/production/batch-number-check?n=NEW-9')->assertOk()->assertJson(['ok' => true]);
        // the typed number is the batch number everywhere
        $this->actingAs($this->andy)->get('/batch-log?status=all')->assertSee('bb ny/25 #7');
        $this->actingAs($this->andy)->get(route('production.show', $b))->assertOk()->assertSee('bb ny/25 #7');
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,labels']);
        $this->actingAs($this->andy)->getJson('/labels/batches?q='.rawurlencode('bb ny'))->assertOk()->assertJsonFragment(['batch_number' => 'bb ny/25 #7']);
    }

    public function test_batch_cannot_start_without_an_approved_recipe_but_codes_are_only_for_suggest(): void
    {
        $this->product->update(['short_code' => null]);
        $this->actingAs($this->andy)->get("/production?product_id={$this->product->id}")->assertDontSee('id="bnSuggest"', false);
        $this->actingAs($this->andy)->post('/production', ['product_id' => $this->product->id, 'target_units' => 100, 'batch_number' => 'NO-CODES-1'])->assertSessionHasNoErrors();
        $this->mmp->update(['status' => 'draft']);
        $this->actingAs($this->andy)->post('/production', ['product_id' => $this->product->id, 'target_units' => 100, 'batch_number' => 'DRAFT-1'])->assertSessionHasErrors('gate');
        $this->assertSame(1, Batch::count());
    }

    public function test_codes_are_validated_in_setup(): void
    {
        $this->assertNotEmpty(\App\Support\CodeRules::brandProblem('S0'));
        $this->assertNotEmpty(\App\Support\CodeRules::productProblem('5B'));
        $this->assertNull(\App\Support\CodeRules::productProblem('5BR'));
        $this->assertNotEmpty(BatchNumberGenerator::validatePattern('{BRAND}{PRODUCT}'));
        $this->assertSame([], BatchNumberGenerator::validatePattern('{BRAND}{PRODUCT}{YYMMDD}{SUFFIX}'));
    }
}
