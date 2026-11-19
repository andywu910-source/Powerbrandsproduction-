<?php

namespace Tests\Feature\Compliance;

use App\Models\AuditLog;
use App\Models\LogDefinition;
use App\Models\LogEntry;
use App\Models\Signature;
use App\Support\LogHub;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class ComplianceHubTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private const UID = '1A4120300000596000002095';
    private const UID2 = '1A4120300000596000002106';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeze('2026-11-18 10:00');   // Wednesday, New York time
        $this->buildPlant();
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,logs']);
        // Pretend every log has existed since October, so a missed period counts as overdue.
        DB::table('log_definitions')->update(['created_at' => '2026-10-01 12:00:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function freeze(string $local): void
    {
        $t = Carbon::parse($local, 'America/New_York');
        Carbon::setTestNow($t);
        CarbonImmutable::setTestNow($t);
    }

    private function def(string $code): LogDefinition
    {
        return LogDefinition::where('code', $code)->firstOrFail();
    }

    private function entry(string $code, string $on, array $data = []): LogEntry
    {
        return LogEntry::create(['log_definition_id' => $this->def($code)->id, 'data' => $data, 'occurred_on' => $on, 'entered_by_user_id' => $this->andy->id]);
    }

    private function tagReplacement(array $over = [])
    {
        return $this->actingAs($this->op)->post('/logs/2-e', ['f' => array_merge([
            'date' => '2026-11-18', 'item' => 'BASIN - DISTILLATE - BULK', 'lost_damaged_tag_old_uid' => self::UID,
            'reason' => 'Lost Tag', 'new_tag_uid_metrc' => self::UID2, 'entered_by' => 'Maria Operator',
        ], $over)]);
    }

    public function test_definitions_sync_even_with_an_old_cached_config(): void
    {
        // On the server, migrate runs while bootstrap/cache/config.php is still the previous release's (no pbp_logs).
        DB::table('log_definitions')->update(['name' => 'stale']);
        config(['pbp_logs' => null]);
        LogHub::syncDefinitions();
        $this->assertSame(48, LogDefinition::count());
        $this->assertSame(0, LogDefinition::where('name', 'stale')->count());
        $this->assertSame('Tag Replacement', $this->def('2-E')->name);
    }

    public function test_all_logs_are_seeded_with_the_drive_headers_and_the_new_fields(): void
    {
        $this->assertSame(48, LogDefinition::count());
        $labels = fn ($code) => array_column($this->def($code)->fields, 'label');
        $this->assertSame(['Date', 'Item', 'Lost / Damaged Tag (Old UID)', 'Reason', 'New Tag UID (Metrc)', 'Entered by'], $labels('2-E'));
        $this->assertContains('Entered by', $labels('4-A'));
        $this->assertContains('QM sign-off', $labels('3-C'));
        $oneA = $labels('1-A');
        $this->assertContains('Metrc tag', $oneA);
        $this->assertNotContains('Package Tag', $oneA);
        foreach (['Unit', 'Method', 'Performed by', 'Witness', 'Performed by signature', 'Witness signature', 'Photo'] as $l) {
            $this->assertContains($l, $oneA);
        }
        $this->assertSame(1, LogDefinition::where('code', '6-D')->count(), 'The two 6-D supplier attestation copies are one log');
        $this->assertTrue($this->def('10-B')->manager_only);
        $this->assertFalse($this->def('CAPA')->confirmed);
    }

    public function test_metrc_uids_must_be_exactly_24_characters(): void
    {
        $this->tagReplacement(['lost_damaged_tag_old_uid' => substr(self::UID, 0, 23)])->assertSessionHasErrors('f.lost_damaged_tag_old_uid');
        $this->tagReplacement(['new_tag_uid_metrc' => self::UID2.'9'])->assertSessionHasErrors('f.new_tag_uid_metrc');
        $this->tagReplacement(['new_tag_uid_metrc' => '1A41203000005960000021-6'])->assertSessionHasErrors('f.new_tag_uid_metrc');
        $this->assertSame(0, LogEntry::count());

        // Spaces and lower case are cleaned up, then it is exactly 24.
        $this->tagReplacement(['new_tag_uid_metrc' => strtolower('1A412030 0000596000002106')])->assertSessionHasNoErrors()->assertRedirect();
        $e = LogEntry::sole();
        $this->assertSame(self::UID2, $e->data['new_tag_uid_metrc']);
        $this->assertEqualsCanonicalizing([self::UID, self::UID2, 'maria operator'], $e->links->pluck('value')->all());
    }

    public function test_entries_record_who_entered_them_and_when(): void
    {
        $this->tagReplacement()->assertSessionHasNoErrors();
        $e = LogEntry::sole();
        $this->assertSame($this->op->id, $e->entered_by_user_id);
        $this->assertTrue($e->created_at->equalTo(now()));
        $this->assertSame('2026-11-18', $e->occurred_on->toDateString());
        $this->assertTrue(AuditLog::where('record_type', 'log_entry')->where('record_id', $e->id)->where('action', 'create')->where('user_id', $this->op->id)->exists());
        $this->actingAs($this->andy)->get('/logs/2-e')->assertOk()->assertSee('Recorded by')->assertSee('Maria Operator')->assertSee('Nov 18, 2026 10:00 AM');

        // Never deleted.
        $this->expectException(\App\Exceptions\RecordDeletionForbidden::class);
        $e->delete();
    }

    public function test_viewers_cannot_add_entries(): void
    {
        $this->actingAs($this->viewer)->get('/logs/2-e')->assertOk()->assertSee('Read-only account.');
        $this->actingAs($this->viewer)->post('/logs/2-e', ['f' => ['date' => '2026-11-18']])->assertForbidden();
    }

    public function test_quality_group_is_only_for_managers(): void
    {
        $this->actingAs($this->op)->get('/logs')->assertOk()->assertSee('Receiving &amp; Inventory (Metrc)', false)
            ->assertDontSee('Quality System &amp; Document Control', false)->assertDontSee('Records Request Log');
        $this->actingAs($this->op)->get('/logs/10-c')->assertNotFound();
        $this->actingAs($this->op)->post('/logs/10-c', ['f' => ['date_time_request_received' => '2026-11-18 09:00']])->assertNotFound();
        $this->assertSame(0, LogEntry::count());

        foreach ([$this->leo, $this->andy] as $mgr) {
            $this->actingAs($mgr)->get('/logs')->assertOk()->assertSee('Quality System &amp; Document Control', false)->assertSee('Managers only');
            $this->actingAs($mgr)->get('/logs/10-c')->assertOk();
        }

        // Related panel never leaks a manager-only entry to an operator.
        $this->entry('10-B', '2026-11-18', ['production_area_room' => 'Preroll room']);
        $this->entry('CLN', '2026-11-18', ['area_room_equipment' => 'Preroll room']);
        foreach (LogEntry::all() as $e) {
            foreach (LogHub::extractLinks($e->definition, $e->data) as $l) {
                $e->links()->create($l);
            }
        }
        $this->actingAs($this->op)->get('/logs/cln')->assertOk()->assertDontSee('Binder Check');
        $this->actingAs($this->leo)->get('/logs/cln')->assertOk()->assertSee('Binder Check');
    }

    public function test_due_and_overdue_follow_each_logs_frequency(): void
    {
        $state = fn ($code) => LogHub::status($this->def($code)->fresh())['state'];

        // Daily: nothing yesterday or today = overdue; yesterday only = due today; today = done.
        $this->assertSame('overdue', $state('T&H'));
        $this->entry('T&H', '2026-11-17');
        $s = LogHub::status($this->def('T&H'));
        $this->assertSame('due', $s['state']);
        $this->assertSame('2026-11-18', $s['due_on']->toDateString());
        $this->entry('T&H', '2026-11-18');
        $this->assertSame('done', $state('T&H'));

        // Weekly (Mon-Sun): last week's entry = due by Sunday Nov 22.
        $this->entry('2-D', '2026-11-12');
        $s = LogHub::status($this->def('2-D'));
        $this->assertSame(['due', '2026-11-22'], [$s['state'], $s['due_on']->toDateString()]);

        // Monthly: nothing in October or November = overdue; an October entry = due by Nov 30.
        $this->assertSame('overdue', $state('3-C'));
        $this->entry('3-C', '2026-10-05');
        $this->assertSame('due', $state('3-C'));

        // A void entry does not count.
        $this->entry('PEST', '2026-11-02')->void('Wrong log', $this->andy);
        $this->assertSame('overdue', $state('PEST'));

        // Per event: never due.
        $this->assertSame('as_needed', $state('VIS'));

        // A log added this month is not overdue for last month.
        DB::table('log_definitions')->where('code', 'CHEM')->update(['created_at' => '2026-11-10 12:00:00']);
        $this->assertSame('due', $state('CHEM'));

        // Per batch: a released batch with no entry is overdue; logging it clears it.
        $this->assertSame('done', $state('5-A'));
        $b = $this->startBatch(100, 'PB-HUB-0001');
        $this->assertSame('due', $state('5-A'));
        DB::table('batches')->where('id', $b->id)->update(['status' => 'released']);
        $s = LogHub::status($this->def('5-A'));
        $this->assertSame(['overdue', ['PB-HUB-0001']], [$s['state'], $s['missing']]);
        $e = $this->entry('5-A', '2026-11-18', ['lot_batch_no' => 'pb-hub-0001']);
        $e->links()->create(['kind' => 'batch', 'value' => 'PB-HUB-0001', 'field_key' => 'lot_batch_no']);
        $this->assertSame('done', $state('5-A'));

        // Strip counts + filters on the hub.
        $this->entry('CLN', '2026-11-17');   // daily, done yesterday = due today
        $board = LogHub::board(LogHub::visible($this->andy));
        $this->assertSame($board['rows']->where('status.state', 'overdue')->count(), $board['counts']['overdue']);
        $this->assertGreaterThan(0, $board['counts']['overdue']);
        $this->assertGreaterThan(0, $board['counts']['today']);
        $this->actingAs($this->andy)->get('/logs?due=overdue')->assertOk()->assertSee('id="lgCountOverdue">'.$board['counts']['overdue'].'<', false);
    }

    public function test_related_logs_share_a_metrc_uid(): void
    {
        $this->tagReplacement()->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post('/logs/4-a', ['f' => [
            'reconciled_on' => '2026-11-18', 'package_uid_metrc' => self::UID2, 'location' => 'Preroll room',
            'expected_qty' => '0g', 'actual_qty' => '200g', 'adjustment_reason_entered' => 'Internal audit', 'entered_by' => 'Maria Operator',
        ]])->assertSessionHasNoErrors();
        $reco = LogEntry::latest('id')->first();

        $this->actingAs($this->op)->get('/logs/4-a?entry='.$reco->id)->assertOk()
            ->assertSee('Related logs')->assertSee('Tag Replacement')->assertSee(self::UID2);
        $this->actingAs($this->op)->get('/logs/related?kind=metrc_uid&value='.strtolower(self::UID2))->assertOk()
            ->assertSee('Tag Replacement')->assertSee('Package Reconciliation');
    }

    public function test_destruction_needs_two_different_signers_and_keeps_a_photo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $base = ['date' => '2026-11-18', 'waste_type' => 'Cannabis', 'item' => 'Trim Strawberry Cream', 'metrc_tag' => self::UID, 'weight' => '1342', 'unit' => 'g',
            'reason' => 'Unusable material', 'method' => 'Grind and mix', 'performed_by' => 'Sae', 'witness' => 'Maria Operator'];

        $this->actingAs($this->op)->post('/logs/1-a', ['f' => $base + ['performed_by_signature' => $this->sae->id, 'witness_signature' => $this->sae->id]])
            ->assertSessionHasErrors('gate');
        $this->assertSame(0, LogEntry::count());

        $photo = \Illuminate\Http\UploadedFile::fake()->create('destruction.jpg', 120, 'image/jpeg');
        $this->actingAs($this->op)->post('/logs/1-a', ['f' => $base + ['performed_by_signature' => $this->sae->id, 'witness_signature' => $this->op->id, 'photo' => $photo]])
            ->assertSessionHasNoErrors();
        $e = LogEntry::sole();
        $this->assertSame(['performed', 'verified'], Signature::where('signable_type', 'log_entry')->where('signable_id', $e->id)->orderBy('id')->pluck('meaning')->all());
        $this->assertSame('destruction.jpg', $e->data['photo']['filename']);
        $this->assertSame('Sae', $e->data['performed_by_signature']['name']);
    }

    public function test_sidebar_has_compliance_above_checklists_and_soon_logs_redirects(): void
    {
        $html = $this->actingAs($this->op)->get('/dashboard')->assertOk()->getContent();
        $hub = strpos($html, route('logs.index'));
        $this->assertNotFalse($hub);
        $this->assertLessThan(strpos($html, route('checklists.index')), $hub);
        $this->actingAs($this->op)->get('/soon/logs')->assertRedirect('/logs');

        config(['pbp.modules_off' => 'logs']);
        $this->actingAs($this->op)->get('/logs')->assertNotFound();
        $this->actingAs($this->op)->get('/soon/logs')->assertOk()->assertSee('coming soon', false);
    }
}
