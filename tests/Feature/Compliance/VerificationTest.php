<?php

namespace Tests\Feature\Compliance;

use App\Models\AuditLog;
use App\Models\LogDefinition;
use App\Models\LogEntry;
use App\Models\Signature;
use App\Models\User;
use App\Support\LogHub;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private const UID = '1A4120300000596000002095';
    private const UID2 = '1A4120300000596000002106';

    private User $admin2;

    protected function setUp(): void
    {
        parent::setUp();
        $t = Carbon::parse('2026-11-19 10:00', 'America/New_York');
        Carbon::setTestNow($t);
        CarbonImmutable::setTestNow($t);
        $this->buildPlant();
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,compliance,logs']);
        DB::table('log_definitions')->update(['created_at' => '2026-10-01 12:00:00']);
        $this->admin2 = User::factory()->admin()->create(['name' => 'Second Admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function def(string $code): LogDefinition
    {
        return LogDefinition::where('code', $code)->firstOrFail();
    }

    private function enter(User $u): LogEntry
    {
        $this->actingAs($u)->post('/logs/2-e', ['f' => [
            'date' => '2026-11-19', 'item' => 'Distillate', 'lost_damaged_tag_old_uid' => self::UID,
            'reason' => 'Lost Tag', 'new_tag_uid_metrc' => self::UID2, 'entered_by' => $u->name,
        ]])->assertRedirect()->assertSessionHasNoErrors();

        return LogEntry::latest('id')->first();
    }

    private function show(LogEntry $e)
    {
        return $this->get('/logs/2-e?entry='.$e->id);
    }

    public function test_new_and_existing_entries_start_pending_and_show_the_badge_and_queue(): void
    {
        $e = $this->enter($this->op);
        $this->assertSame('pending', $e->fresh()->verification_status);
        $this->assertNull($e->verified_at);
        $this->show($e)->assertOk()->assertSee('Pending verification');
        $this->get('/logs')->assertOk()->assertSeeInOrder(['id="lgCountPending">1<', 'Needs verification'], false);
        $this->get('/logs/verification')->assertOk()->assertSee('Tag Replacement')->assertSee('Maria Operator')
            ->assertSee('/logs/2-e?entry='.$e->id, false);
        // Rows written without the column (e.g. before the migration) default to pending too.
        $raw = LogEntry::create(['log_definition_id' => $this->def('VIS')->id, 'data' => [], 'occurred_on' => '2026-11-19', 'entered_by_user_id' => $this->op->id]);
        $this->assertSame('pending', $raw->fresh()->verification_status);
    }

    public function test_only_admins_verify_and_only_admins_see_the_button(): void
    {
        $e = $this->enter($this->op);
        foreach ([$this->leo, $this->op, $this->viewer] as $u) {   // supervisor, operator, viewer
            $this->actingAs($u);
            $this->show($e)->assertOk()->assertDontSee('id="lgVerify"', false)->assertDontSee('Verify (sign as', false);
            $this->post("/logs/entries/{$e->id}/verify")->assertForbidden();
            $this->post("/logs/entries/{$e->id}/send-back", ['reason' => 'x'])->assertForbidden();
        }
        $this->assertSame('pending', $e->fresh()->verification_status);
        $this->actingAs($this->andy);
        $this->show($e)->assertSee('id="lgVerify"', false)->assertSee('Verify (sign as Andy Wu)');
    }

    public function test_an_admin_cannot_verify_their_own_entry(): void
    {
        $e = $this->enter($this->andy);
        $this->show($e)->assertSee('id="lgOwnEntry"', false)->assertDontSee('id="lgVerify"', false);
        $this->post("/logs/entries/{$e->id}/verify")->assertSessionHasErrors('gate');
        $this->post("/logs/entries/{$e->id}/send-back", ['reason' => 'mine'])->assertSessionHasErrors('gate');
        $fresh = $e->fresh();
        $this->assertSame('pending', $fresh->verification_status);
        $this->assertNull($fresh->voided_at);
        $this->assertSame(0, Signature::where('signable_type', 'log_entry')->where('meaning', 'verified')->count());
    }

    public function test_verification_is_an_esignature_with_user_and_time_in_the_audit_trail(): void
    {
        $e = $this->enter($this->andy);
        $this->actingAs($this->admin2)->post("/logs/entries/{$e->id}/verify")->assertRedirect()->assertSessionHasNoErrors();
        $e->refresh();
        $this->assertSame('verified', $e->verification_status);
        $this->assertSame($this->admin2->id, $e->verified_by_user_id);
        $this->assertTrue($e->verified_at->equalTo(now()));
        $sig = Signature::findOrFail($e->verification_signature_id);
        $this->assertSame(['verified', $this->admin2->id, 'log_entry', $e->id], [$sig->meaning, $sig->user_id, $sig->signable_type, $sig->signable_id]);
        $audit = AuditLog::where('record_type', 'log_entry')->where('record_id', $e->id)->where('user_id', $this->admin2->id)->pluck('action')->all();
        $this->assertContains('sign', $audit);
        $this->assertContains('verify', $audit);
        $this->assertNotNull(AuditLog::where('action', 'verify')->where('record_id', $e->id)->value('created_at'));
        $this->show($e)->assertSee('Verified by Second Admin')->assertSee('>Verified<', false)->assertDontSee('id="lgVerify"', false);
        // Twice is refused.
        $this->actingAs($this->admin2)->post("/logs/entries/{$e->id}/verify")->assertSessionHasErrors('gate');
        $this->assertSame(1, Signature::where('signable_id', $e->id)->where('meaning', 'verified')->count());
    }

    public function test_send_back_voids_with_the_reason_and_marks_returned(): void
    {
        $e = $this->enter($this->op);
        $this->actingAs($this->andy)->post("/logs/entries/{$e->id}/send-back", ['reason' => 'Wrong new tag'])->assertRedirect();
        $e->refresh();
        $this->assertSame('returned', $e->verification_status);
        $this->assertNotNull($e->voided_at);
        $this->assertSame('Returned by Andy Wu: Wrong new tag', $e->void_reason);
        $this->assertTrue(AuditLog::where('action', 'return')->where('record_id', $e->id)->where('user_id', $this->andy->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'void')->where('record_id', $e->id)->exists());
        $this->show($e)->assertSee('Returned: Returned by Andy Wu: Wrong new tag');
        $this->get('/logs/verification')->assertSee('Nothing is waiting for verification.');
        $this->post("/logs/entries/{$e->id}/verify")->assertSessionHasErrors('gate');
    }

    public function test_queue_count_follows_what_each_user_may_see(): void
    {
        $older = $this->enter($this->op);
        $this->travel(5)->minutes();
        $newer = $this->enter($this->leo);
        LogEntry::create(['log_definition_id' => $this->def('7-A')->id, 'data' => [], 'occurred_on' => '2026-11-19', 'entered_by_user_id' => $this->andy->id]);
        $voided = $this->enter($this->op);
        $voided->void('typo', $this->leo);
        $verified = $this->enter($this->op);
        $this->actingAs($this->admin2)->post("/logs/entries/{$verified->id}/verify");

        $this->actingAs($this->andy)->get('/logs')->assertSee('id="lgCountPending">3<', false);   // incl. Quality (7-A)
        $this->get('/logs/verification')->assertSeeInOrder(['7-A', "<td>{$newer->id}</td>", "<td>{$older->id}</td>"], false)
            ->assertSee('id="lgQueueCount">3<', false);
        $this->actingAs($this->op)->get('/logs')->assertSee('id="lgCountPending">2<', false);       // operators can't see Quality
        $this->get('/logs/verification')->assertOk()->assertDontSee('7-A')->assertSee('id="lgQueueCount">2<', false)
            ->assertSee('An admin verifies each entry.');
    }

    public function test_unverified_entries_still_count_for_due_status(): void
    {
        LogEntry::create(['log_definition_id' => $this->def('CLN')->id, 'data' => [], 'occurred_on' => '2026-11-19', 'entered_by_user_id' => $this->op->id]);
        $this->assertSame('done', LogHub::status($this->def('CLN'))['state']);
    }

    public function test_old_compliance_checklists_tab_is_retired_into_the_hub(): void
    {
        config(['pbp.modules_off' => 'compliance']);
        $this->actingAs($this->andy);
        foreach (['/compliance', '/compliance/weekly', '/compliance/monthly', '/compliance/history', '/compliance/setup'] as $p) {
            $this->get($p)->assertRedirect(route('logs.index'));
        }
        $this->post('/compliance/setup/items', [])->assertRedirect(route('logs.index'));
        $html = $this->get('/dashboard')->assertOk()->getContent();
        $side = substr($html, strpos($html, '<aside'), strpos($html, '</aside>') - strpos($html, '<aside'));
        $this->assertStringNotContainsString('Compliance checklists', $side);
        $this->assertStringNotContainsString('/compliance"', $side);
        $this->assertLessThan(strpos($side, '>Checklists<'), strpos($side, '>Compliance<'));
        $this->assertStringContainsString('href="'.route('checklists.index').'"', $side);   // the separate Checklists tab stays
        $this->get('/checklists')->assertOk();
        $this->get('/logs')->assertOk()->assertSee('cmp-tabs', false);
        $this->assertStringContainsString('css/compliance.css', $this->get('/logs')->getContent());   // hub styles still load

        // Where the hub itself is hidden, the old links are a plain 404 as before.
        config(['pbp.logs_preview_only' => true, 'pbp.preview_links' => true]);
        $this->get('/compliance/weekly')->assertNotFound();
    }
}
