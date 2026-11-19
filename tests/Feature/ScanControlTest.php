<?php

namespace Tests\Feature;

use App\Models\LogDefinition;
use App\Models\MaterialLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

/** The scan control (partials/scan.blade.php + public/js/scan.js) on every tag / UID / barcode field, and the lot lookup. */
class ScanControlTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private const UID = '1A4120300000596000002095';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,labels,logs']);
    }

    private function scanButtons(string $html): int
    {
        return substr_count($html, 'class="pb-scan-btn"');
    }

    public function test_every_hub_metrc_field_has_the_scan_control(): void
    {
        $metrc = 0;
        $tagText = 0;
        foreach (LogDefinition::where('active', true)->get() as $d) {
            $html = $this->actingAs($this->andy)->get('/logs/'.$d->slug)->assertOk()->getContent();
            $want = collect($d->fields)->where('type', 'metrc')->count();
            $wantText = collect($d->fields)->filter(fn ($f) => in_array($f['type'], ['textarea', 'text'], true) && preg_match('/\b(tags?|uids?|barcodes?)\b/i', $f['label']))->count();
            $this->assertSame($want, substr_count($html, 'data-scan="metrc"'), $d->code.' metrc fields');
            $this->assertSame($want + $wantText, $this->scanButtons($html), $d->code.' scan buttons');
            foreach (collect($d->fields)->where('type', 'metrc') as $f) {
                $this->assertStringContainsString('data-scan-for="lgf_'.$f['key'].'"', $html);
                $this->assertStringContainsString('data-scan-msg-for="lgf_'.$f['key'].'"', $html);
            }
            $metrc += $want;
            $tagText += $wantText;
        }
        $this->assertGreaterThanOrEqual(10, $metrc);
        $this->assertSame(1, $tagText);   // 9-A BPR "Metrc package tags / cannabis IDs used"
    }

    public function test_bpr_tag_list_textarea_appends_scans(): void
    {
        $d = LogDefinition::where('code', '9-A BPR')->firstOrFail();
        $html = $this->actingAs($this->andy)->get('/logs/'.$d->slug)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<textarea[^>]+data-scan="append"/', $html);
    }

    public function test_scan_script_and_decoder_are_served_locally(): void
    {
        $html = $this->actingAs($this->op)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('js/scan.js', $html);
        $this->assertStringContainsString('css/scan.css', $html);
        $this->assertStringContainsString('js/vendor/zxing-library-0.21.3.min.js', $html);
        $this->assertStringContainsString('content="/lots/lookup"', $html);
        $this->assertFileExists(public_path('js/vendor/zxing-library-0.21.3.min.js'));
        $js = file_get_contents(public_path('js/scan.js'));
        $this->assertStringContainsString('BarcodeDetector', $js);
        $this->assertStringContainsString("e.key !== 'Enter'", $js);   // scanner Enter handling
        $this->assertStringContainsString('e.preventDefault();   // a handheld scanner', $js);
        $this->assertStringNotContainsString('cdn', strtolower($js));
    }

    public function test_receiving_batch_log_labels_and_deviations_fields_have_scan(): void
    {
        $r = $this->actingAs($this->op)->get('/receiving/new')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="metrc_package_uid"[^>]*data-scan="metrc"/', $r);
        $this->assertStringContainsString('data-scan-for="rc_uid"', $r);

        $r = $this->actingAs($this->op)->get('/receiving')->assertOk()->getContent();
        $this->assertStringContainsString('data-scan-for="rc_q"', $r);
        $this->assertMatchesRegularExpression('/id="rc_q"[^>]*data-scan="search"/', $r);   // Enter still searches

        $r = $this->actingAs($this->op)->get('/batch-log')->assertOk()->getContent();
        $this->assertStringContainsString('data-scan-for="bl_q"', $r);

        $b = $this->startBatch();
        $r = $this->actingAs($this->andy)->get(route('batch-log.show', $b, false))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="barcode_number"[^>]*data-scan="barcode"/', $r);

        $r = $this->actingAs($this->andy)->get('/labels')->assertOk()->getContent();
        $this->assertStringContainsString('data-scan-for="pickBatch"', $r);
        $r = $this->actingAs($this->andy)->get('/labels/new?batch_id='.$b->id)->assertOk()->getContent();
        $this->assertStringContainsString('data-scan-for="f_bv"', $r);

        $r = $this->actingAs($this->andy)->get('/deviations')->assertOk()->getContent();
        $this->assertStringContainsString('data-scan-for="devOriginRef"', $r);
        $this->assertStringContainsString('data-scan-for="devBatch"', $r);
    }

    public function test_weigh_out_has_lot_scan_wired_to_the_lot_select(): void
    {
        $b = $this->startBatch();
        $this->throughStep4($b);
        $bn = $b->batch_number;
        $this->actingAs($this->op)->post("/production/{$bn}/calculate")->assertSessionHasNoErrors();
        $this->actingAs($this->leo)->post("/production/{$bn}/calculate/confirm", ['signer_id' => $this->leo->id])->assertSessionHasNoErrors();
        $html = $this->actingAs($this->op)->get("/production/{$bn}")->assertOk()->getContent();
        $this->assertStringContainsString('data-scan-lot="#s5_pick_lot"', $html);
        $this->assertStringContainsString('id="s5_pick_lot"', $html);
        $this->assertStringContainsString('data-scan-line="#s5_pick_line"', $html);
        $this->assertStringContainsString('data-line="'.$this->mmp->lines()->first()->id.'"', $html);
        // The helper scan box is not posted with the pick form.
        $this->assertMatchesRegularExpression('/id="s5_pick_scan"[^>]*form="s5_none"/', $html);
    }

    public function test_lot_lookup_endpoint(): void
    {
        $this->flowerLot->update(['metrc_package_uid' => self::UID]);
        $this->actingAs($this->op)->getJson('/lots/lookup?uid='.strtolower(self::UID).'%20')->assertOk()
            ->assertJson(['ok' => true, 'lot' => ['id' => $this->flowerLot->id, 'public_id' => $this->flowerLot->public_id, 'ingredient' => 'Ground flower']]);

        $this->actingAs($this->op)->getJson('/lots/lookup?uid=1A4120300000596000009999')->assertNotFound()->assertJson(['ok' => false])
            ->assertJsonFragment(['message' => 'No received lot has Metrc package UID 1A4120300000596000009999.']);

        $this->flowerLot->update(['status' => 'quarantine']);
        $r = $this->actingAs($this->op)->getJson('/lots/lookup?uid='.self::UID)->assertStatus(409)->assertJson(['ok' => false]);
        $this->assertStringContainsString('not released', $r->json('message'));

        $this->flowerLot->update(['status' => 'released', 'expiration_date' => now()->subDay()->toDateString()]);
        $r = $this->actingAs($this->op)->getJson('/lots/lookup?uid='.self::UID)->assertStatus(409);
        $this->assertStringContainsString('expired', $r->json('message'));

        $this->actingAs($this->op)->getJson('/lots/lookup?uid=')->assertStatus(422);
        auth()->logout();
        $this->getJson('/lots/lookup?uid='.self::UID)->assertUnauthorized();
    }

    public function test_lookup_prefers_the_usable_lot_when_a_uid_repeats(): void
    {
        $this->flowerLot->update(['metrc_package_uid' => self::UID]);
        $old = MaterialLot::create(['public_id' => 'RCV-OLD', 'received_at' => now(), 'supplier_id' => $this->flowerLot->supplier_id, 'ingredient_id' => $this->flower->id,
            'received_qty' => 1, 'unit' => 'g', 'status' => 'depleted', 'metrc_package_uid' => self::UID]);
        $this->actingAs($this->op)->getJson('/lots/lookup?uid='.self::UID)->assertOk()->assertJsonPath('lot.id', $this->flowerLot->id);
        $this->assertNotSame($old->id, $this->flowerLot->id);
    }

    public function test_server_side_metrc_validation_unchanged(): void
    {
        $d = LogDefinition::where('code', '1-C')->firstOrFail();
        $f = collect($d->fields)->firstWhere('type', 'metrc');
        $this->actingAs($this->op)->post('/logs/'.$d->slug, ['f' => [$f['key'] => 'SHORT', collect($d->fields)->firstWhere('type', 'date')['key'] => '2026-11-18']])
            ->assertSessionHasErrors('f.'.$f['key']);
    }
}
