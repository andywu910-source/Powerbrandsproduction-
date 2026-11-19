<?php

namespace Tests\Feature\Compliance;

use App\Models\LogDefinition;
use App\Models\LogEntry;
use App\Support\LogHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

/** Nov 19 (Andy): 1-C without checkboxes, 4-A one Location + "Wrong location?", 3-B / BAL / VIS retired. */
class TrimmedLogsTest extends TestCase
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

    public function test_1c_asks_for_no_checkboxes_but_old_values_still_show(): void
    {
        $d = $this->def('1-C');
        $this->assertSame(6, collect($d->fields)->where('type', 'bool')->where('removed', true)->count(), 'all six checkboxes kept as removed');
        $this->assertSame([], collect(LogHub::formFields($d))->where('type', 'bool')->all());

        $form = $this->actingAs($this->op)->get('/logs/1-c')->assertOk()->getContent();
        $this->assertStringNotContainsString('type="checkbox" id="lgf_', $form);
        foreach (['f[manifest_verified]', 'f[accepted_in_metrc]', 'Manifest Verified'] as $gone) {
            $this->assertStringNotContainsString($gone, $form);
        }
        $this->assertStringContainsString('name="f[package_uid]"', $form);
        $this->assertStringContainsString('name="f[transfer_manifest_no]"', $form);

        $this->actingAs($this->op)->post('/logs/1-c', ['f' => ['date' => '2026-11-19', 'package_uid' => self::UID, 'manifest_verified' => 1]])->assertSessionHasNoErrors();
        $new = LogEntry::latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('manifest_verified', $new->data, 'removed fields are not stored on new entries');
        $this->assertSame(LogEntry::PENDING, $new->verification_status, 'verification unchanged');
        $page = $this->actingAs($this->andy)->get('/logs/1-c?entry='.$new->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('<dt>Manifest Verified', $page);

        $old = LogEntry::create(['log_definition_id' => $d->id, 'occurred_on' => '2026-11-10', 'entered_by_user_id' => $this->op->id,
            'data' => ['date' => '2026-11-10', 'package_uid' => self::UID, 'manifest_verified' => true, 'accepted_in_metrc' => false]]);
        $page = $this->actingAs($this->andy)->get('/logs/1-c?entry='.$old->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<dt>Manifest Verified <span class="pb-muted lg-removed">\(no longer asked\)</span></dt>\s*<dd>✓</dd>#u', $page);
        $this->assertMatchesRegularExpression('#<dt>Accepted in Metrc <span[^>]*>\(no longer asked\)</span></dt>\s*<dd>—</dd>#u', $page);
    }

    public function test_4a_has_one_location_and_a_wrong_location_note(): void
    {
        $d = $this->def('4-A');
        $this->assertSame(['reconciled_on', 'package_uid_metrc', 'location', 'wrong_location', 'wrong_location_note', 'expected_qty', 'actual_qty', 'adjustment_reason_entered', 'entered_by'],
            array_column(LogHub::formFields($d), 'key'));

        $form = $this->actingAs($this->op)->get('/logs/4-a')->assertOk()->getContent();
        $this->assertStringNotContainsString('f[expected_location]', $form);
        $this->assertStringNotContainsString('f[actual_location]', $form);
        $this->assertStringContainsString('name="f[location]"', $form);
        $this->assertStringContainsString('id="lgf_wrong_location" name="f[wrong_location]"', $form);
        $this->assertStringContainsString('data-show-if="lgf_wrong_location"', $form);
        $this->assertStringContainsString('(required when ticked)', $form);

        $base = ['reconciled_on' => '2026-11-19', 'package_uid_metrc' => self::UID, 'location' => 'Preroll room'];
        $this->actingAs($this->op)->post('/logs/4-a', ['f' => $base + ['wrong_location' => 1]])
            ->assertSessionHasErrors(['f.wrong_location_note' => 'Wrong location note is required when “Wrong location?” is ticked.']);
        $this->actingAs($this->op)->post('/logs/4-a', ['f' => $base + ['wrong_location' => 1, 'wrong_location_note' => 'Found in vault, moved back']])->assertSessionHasNoErrors();
        $e = LogEntry::latest('id')->firstOrFail();
        $this->assertTrue($e->data['wrong_location']);
        $this->assertSame('Found in vault, moved back', $e->data['wrong_location_note']);
        $this->actingAs($this->op)->post('/logs/4-a', ['f' => $base])->assertSessionHasNoErrors();
        $this->assertFalse(LogEntry::latest('id')->first()->data['wrong_location']);

        $old = LogEntry::create(['log_definition_id' => $d->id, 'occurred_on' => '2026-11-10', 'entered_by_user_id' => $this->op->id,
            'data' => ['reconciled_on' => '2026-11-10', 'package_uid_metrc' => self::UID, 'expected_location' => 'Vault', 'actual_location' => 'Preroll room']]);
        $page = $this->actingAs($this->andy)->get('/logs/4-a?entry='.$old->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<dt>Expected Location <span[^>]*>\(no longer asked\)</span></dt>\s*<dd>Vault</dd>#u', $page);
        $this->assertMatchesRegularExpression('#<dt>Actual Location <span[^>]*>\(no longer asked\)</span></dt>\s*<dd>Preroll room</dd>#u', $page);
    }

    public function test_3b_bal_and_vis_are_retired_everywhere(): void
    {
        $old = LogEntry::create(['log_definition_id' => $this->def('VIS')->id, 'occurred_on' => '2026-11-10', 'entered_by_user_id' => $this->op->id,
            'data' => ['date' => '2026-11-10', 'visitor_full_name' => 'Old Visitor']]);
        foreach (['3-B' => '3-b', 'BAL' => 'bal', 'VIS' => 'vis'] as $code => $slug) {
            $this->assertFalse($this->def($code)->active, $code);
            $this->actingAs($this->op)->get("/logs/$slug")->assertNotFound();
            $this->actingAs($this->op)->post("/logs/$slug", ['f' => ['date' => '2026-11-19']])->assertNotFound();
            $page = $this->actingAs($this->leo)->get("/logs/$slug")->assertOk()->getContent();
            $this->assertStringContainsString('id="lgRetired"', $page);
            $this->assertStringNotContainsString('id="lgForm"', $page);
        }
        $this->assertStringContainsString('Old Visitor', $this->actingAs($this->leo)->get('/logs/vis?entry='.$old->id)->getContent());

        $hub = $this->actingAs($this->andy)->get('/logs')->assertOk()->getContent();
        foreach (['Container Label', 'Balance Check', 'Visitor Log', '/logs/3-b"', '/logs/bal"', '/logs/vis"'] as $gone) {
            $this->assertStringNotContainsString($gone, $hub);
        }
        $this->assertStringContainsString('Scale Calibration', $hub, '3-C stays');
        $this->actingAs($this->andy)->get('/logs?finding=3')->assertOk()->assertDontSee('Container Label')->assertDontSee('Balance Check');
        $queue = $this->actingAs($this->andy)->get('/logs/verification')->assertOk()->getContent();
        $this->assertStringNotContainsString('Old Visitor', $queue);
        $this->assertStringNotContainsString('/logs/vis', $queue);

        // Related logs skip them: a 3-B entry sharing a UID with a 1-C entry is not listed.
        $c = LogEntry::create(['log_definition_id' => $this->def('3-B')->id, 'occurred_on' => '2026-11-10', 'entered_by_user_id' => $this->op->id,
            'data' => ['metrc_package_uid' => self::UID, 'strain_product_name' => 'Old label']]);
        \App\Models\LogEntryLink::create(['log_entry_id' => $c->id, 'kind' => 'metrc_uid', 'value' => self::UID, 'field_key' => 'metrc_package_uid']);
        $this->actingAs($this->op)->post('/logs/1-c', ['f' => ['date' => '2026-11-19', 'package_uid' => self::UID]])->assertSessionHasNoErrors();
        $e = LogEntry::latest('id')->first();
        $this->assertTrue(LogHub::related($e->fresh('links'), $this->andy)->isEmpty());
        $this->actingAs($this->andy)->get('/logs/related?kind=metrc_uid&value='.self::UID)->assertOk()->assertDontSee('Container Label');
    }
}
