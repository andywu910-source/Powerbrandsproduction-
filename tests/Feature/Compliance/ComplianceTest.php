<?php

namespace Tests\Feature\Compliance;

use App\Models\AuditLog;
use App\Models\ComplianceEntry;
use App\Models\ComplianceItem;
use App\Models\User;
use App\Support\Compliance;
use App\Support\Modules;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\ComplianceSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class ComplianceTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private User $jack;

    protected function setUp(): void
    {
        parent::setUp();
        // Wed Nov 18 2026, 11 PM in New York = Thu 04:00 UTC: still the week of Mon 11/16.
        $now = Carbon::parse('2026-11-18 23:00', 'America/New_York');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
        $this->buildPlant();
        $this->jack = User::factory()->create(['name' => 'Jack Smith']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function weekly(int $n): ComplianceItem
    {
        return ComplianceItem::where('frequency', 'weekly')->orderBy('sort_order')->skip($n - 1)->firstOrFail();
    }

    private function save(User $u, ComplianceItem $it, array $data, string $period = '2026-11-16', string $freq = 'weekly')
    {
        return $this->actingAs($u)->post("/compliance/$freq/$period/items/{$it->id}", $data);
    }

    public function test_tab_is_on_whatever_the_env_says_and_the_items_are_seeded_exactly(): void
    {
        foreach (['', 'production,batchlog', '"production, schedule"'] as $v) {
            config(['pbp.modules' => $v]);
            $this->assertTrue(Modules::enabled('compliance'));
        }
        config(['pbp.modules_off' => 'compliance']);
        $this->assertFalse(Modules::enabled('compliance'));
        $this->actingAs($this->andy)->get('/compliance/weekly')->assertNotFound();
        config(['pbp.modules_off' => '']);

        $w = ComplianceItem::where('frequency', 'weekly')->orderBy('sort_order')->pluck('title')->all();
        $m = ComplianceItem::where('frequency', 'monthly')->orderBy('sort_order')->pluck('title')->all();
        $this->assertCount(15, $w);
        $this->assertCount(16, $m);
        $this->assertSame('2-D Weekly Tag Audit', $w[0]);
        $this->assertSame('Supervisor review', $w[13]);
        $this->assertSame('3-C Scale Calibration', $m[0]);
        $this->assertSame('Monthly review', $m[12]);
        $this->assertSame('Weekly 10% cycle count (SOP-INV-03)', $w[12]);
        $this->assertSame('Formulation Log / batch record check', $w[14]);
        $this->assertSame('Metrc & surveillance access review', $m[15]);
        $this->assertSame('Walk every room (Vape Production, Cold Room, Production Room), confirm each package has a Metrc tag, log it.', $this->weekly(1)->description);

        $page = $this->actingAs($this->op)->get('/compliance/weekly')->assertOk()->getContent();
        $this->assertStringContainsString(e("Internal checklists, not official OCM documents. Logs live in Andy's Drive at Compliance / LOGS."), $page);
        $this->assertStringContainsString('Week of Nov 16, 2026', $page);
        $this->assertStringContainsString('0 of 15 done', $page);
        $this->assertStringContainsString('href="'.route('compliance.home').'"', $this->actingAs($this->op)->get('/dashboard')->getContent());
        $this->actingAs($this->op)->get('/compliance/monthly')->assertOk()->assertSee('November 2026')->assertSee('0 of 16 done');
    }

    public function test_periods_are_mon_to_sun_in_new_york_and_calendar_months(): void
    {
        $this->assertSame('2026-11-16', Compliance::periodStart('weekly', '2026-11-22')->toDateString());   // Sunday
        $this->assertSame('2026-11-23', Compliance::periodStart('weekly', '2026-11-23')->toDateString());   // Monday
        $this->assertSame('2026-11-16', Compliance::periodStart('weekly')->toDateString());                 // 11 PM ET Wed (Thu UTC)
        $this->assertSame('2026-11-01', Compliance::periodStart('monthly', '2026-11-30')->toDateString());
        $this->assertSame('2026-12-01', Compliance::step('monthly', Compliance::periodStart('monthly', '2026-11-30'), 1)->toDateString());
    }

    public function test_check_defaults_to_me_and_today_and_is_audited_and_uncheck_clears(): void
    {
        $it = $this->weekly(3);
        $this->save($this->op, $it, ['done' => 1])->assertRedirect();
        $e = ComplianceEntry::where('item_id', $it->id)->firstOrFail();
        $this->assertTrue($e->done);
        $this->assertSame($this->op->id, $e->completed_by_user_id);
        $this->assertSame('2026-11-18', $e->completed_on->toDateString());
        $this->assertNull($e->assignee_user_id);
        $this->actingAs($this->op)->get('/compliance/weekly')->assertSee('1 of 15 done');

        $this->save($this->op, $it, ['done' => 1, 'completed_by_user_id' => $this->sae->id, 'completed_on' => '2026-11-17', 'assignee_user_id' => $this->leo->id, 'note' => 'all good']);
        $e->refresh();
        $this->assertSame([$this->sae->id, '2026-11-17', $this->leo->id, 'all good'], [$e->completed_by_user_id, $e->completed_on->toDateString(), $e->assignee_user_id, $e->note]);

        $this->save($this->op, $it, ['done' => 0]);
        $e->refresh();
        $this->assertFalse($e->done);
        $this->assertNull($e->completed_by_user_id);
        $this->assertNull($e->completed_on);
        $this->assertSame($this->leo->id, $e->assignee_user_id);   // assignee and note stay
        $logs = AuditLog::where('record_type', 'compliance_entry')->where('record_id', $e->id)->get();
        $this->assertSame(['create', 'update', 'update'], $logs->pluck('action')->all());
        $this->assertStringContainsString('"done":false', json_encode($logs->last()->toArray()));
    }

    public function test_jack_is_never_assignable_or_completed_by_server_side(): void
    {
        $it = $this->weekly(1);
        $page = $this->actingAs($this->andy)->get('/compliance/weekly')->getContent();
        $this->assertStringNotContainsString('Jack Smith', $page);
        $this->assertStringContainsString('Maria Operator', $page);
        $this->assertFalse(Compliance::pickable()->contains('id', $this->jack->id));

        $this->save($this->andy, $it, ['done' => 1, 'assignee_user_id' => $this->jack->id])->assertSessionHasErrors('assignee_user_id');
        $this->save($this->andy, $it, ['done' => 1, 'completed_by_user_id' => $this->jack->id])->assertSessionHasErrors('completed_by_user_id');
        $this->assertSame(0, ComplianceEntry::count());

        // Jack checking it himself cannot default to "completed by Jack".
        $this->save($this->jack, $it, ['done' => 1])->assertSessionHasErrors('completed_by_user_id');
        $this->assertSame(0, ComplianceEntry::count());

        // Config list (names or emails) excludes more people; first name match is case-insensitive.
        $jk = User::factory()->create(['name' => 'JACK']);
        $this->assertTrue(Compliance::excluded($jk));
        $this->assertFalse(Compliance::excluded($this->sae));
        config(['pbp.compliance.excluded' => 'Jack, '.$this->sae->email]);
        $this->assertTrue(Compliance::excluded($this->sae));
        $this->save($this->andy, $it, ['done' => 1, 'assignee_user_id' => $this->sae->id])->assertSessionHasErrors('assignee_user_id');
        // Inactive users are not pickable either.
        config(['pbp.compliance.excluded' => 'Jack']);
        $this->op->update(['is_active' => false]);
        $this->save($this->andy, $it, ['done' => 1, 'assignee_user_id' => $this->op->id])->assertSessionHasErrors('assignee_user_id');
    }

    public function test_history_is_read_only_except_for_admins(): void
    {
        $it = $this->weekly(2);
        $this->actingAs($this->leo)->get('/compliance/weekly?period=2026-11-09')->assertOk()->assertSee('read-only');
        $this->save($this->leo, $it, ['done' => 1], '2026-11-09')->assertForbidden();
        $this->save($this->op, $it, ['done' => 1], '2026-11-09')->assertForbidden();
        $this->save($this->andy, $it, ['done' => 1, 'completed_on' => '2026-11-13'], '2026-11-09')->assertRedirect();
        $this->assertTrue(ComplianceEntry::whereDate('period_start', '2026-11-09')->where('done', true)->exists());
        $this->save($this->andy, $it, ['done' => 1], '2026-11-10')->assertNotFound();   // not a Monday

        // Viewers can read but never check.
        $this->actingAs($this->viewer)->get('/compliance/weekly')->assertOk();
        $this->save($this->viewer, $it, ['done' => 1])->assertForbidden();

        $h = $this->actingAs($this->op)->get('/compliance/history')->assertOk()->getContent();
        $this->assertStringContainsString('Week of Nov 9, 2026', $h);
        $this->assertStringContainsString('1 of 15 done', $h);
        $this->assertStringContainsString('November 2026', $h);

        // Monthly: last month read-only for a supervisor, current month open.
        $m = ComplianceItem::where('frequency', 'monthly')->orderBy('sort_order')->first();
        $this->save($this->leo, $m, ['done' => 1], '2026-10-01', 'monthly')->assertForbidden();
        $this->save($this->leo, $m, ['done' => 1], '2026-11-01', 'monthly')->assertRedirect();
        $this->actingAs($this->leo)->get('/compliance/monthly')->assertSee('1 of 16 done');
        $this->save($this->leo, $it, ['done' => 1], '2026-11-01', 'monthly')->assertNotFound();   // weekly item on monthly list
    }

    public function test_admins_edit_the_template_and_history_keeps_hidden_items(): void
    {
        $it = $this->weekly(14);
        $this->save($this->op, $it, ['done' => 1]);
        $this->actingAs($this->leo)->post("/compliance/setup/items/{$it->id}", ['title' => 'x'])->assertForbidden();
        $this->actingAs($this->leo)->post('/compliance/setup/items', ['frequency' => 'weekly', 'title' => 'x'])->assertForbidden();

        $this->actingAs($this->andy)->post("/compliance/setup/items/{$it->id}", ['title' => 'Supervisor review (initials)', 'description' => 'd', 'hidden' => 1])->assertRedirect();
        $this->assertTrue($it->fresh()->hidden);
        $this->actingAs($this->andy)->get('/compliance/weekly')->assertSee('Supervisor review (initials)')->assertSee('1 of 15 done');   // has an entry this week
        $this->actingAs($this->andy)->get('/compliance/weekly?period=2026-11-23')->assertDontSee('Supervisor review (initials)')->assertSee('0 of 14 done');

        $this->actingAs($this->andy)->post('/compliance/setup/items', ['frequency' => 'weekly', 'title' => 'New check'])->assertRedirect();
        $this->actingAs($this->andy)->get('/compliance/weekly?period=2026-11-23')->assertSee('New check')->assertSee('0 of 15 done');

        $one = $this->weekly(1);
        $two = $this->weekly(2);
        $this->actingAs($this->andy)->post("/compliance/setup/items/{$two->id}", ['move' => 'up'])->assertRedirect();
        $this->assertSame($two->id, $this->weekly(1)->id);
        $this->assertSame($one->id, $this->weekly(2)->id);
        $this->assertTrue(AuditLog::where('record_type', 'compliance_item')->where('action', 'update')->exists());
        $this->actingAs($this->leo)->get('/compliance/setup')->assertOk()->assertSee('Only admins');
    }

    public function test_nov18_items_quarterly_annual_and_standing_logs(): void
    {
        $c = fn ($f) => ComplianceItem::where('frequency', $f)->count();
        $this->assertSame([15, 16, 1, 5], [$c('weekly'), $c('monthly'), $c('quarterly'), $c('annual')]);
        $this->assertSame(['Complaint log maintained (24-hr OCM notice for serious adverse events/recalls, 125.9(a))', 'CAPA / deviation log maintained (123.6(g))'],
            ComplianceItem::where('standing', true)->orderBy('sort_order')->pluck('title')->all());
        $this->assertSame(0, ComplianceItem::where('frequency', '!=', 'annual')->where('standing', true)->count());
        $this->assertSame(0, ComplianceItem::where('title', 'Physical vs Metrc spot check')->count());

        // Periods: calendar quarter and calendar year, New York time.
        $this->assertSame('2026-10-01', Compliance::periodStart('quarterly', '2026-12-31')->toDateString());
        $this->assertSame('2027-01-01', Compliance::step('quarterly', Compliance::periodStart('quarterly'), 1)->toDateString());
        $this->assertSame('2026-01-01', Compliance::periodStart('annual')->toDateString());
        $this->assertSame('2025-01-01', Compliance::step('annual', Compliance::periodStart('annual'), -1)->toDateString());

        $q = $this->actingAs($this->op)->get('/compliance/quarterly')->assertOk()->getContent();
        $this->assertStringContainsString('Q4 2026', $q);
        $this->assertStringContainsString('0 of 1 done', $q);
        $a = $this->actingAs($this->op)->get('/compliance/annual')->assertOk()->getContent();
        $this->assertStringContainsString('Year 2026', $a);
        $this->assertStringContainsString('0 of 5 done', $a);
        $this->assertStringContainsString('id="cmpStanding"', $a);
        $this->assertLessThan(strpos($a, 'CAPA / deviation log'), strpos($a, 'id="cmpStanding"'));
        $this->assertGreaterThan(strpos($a, 'Mock recall drill'), strpos($a, 'id="cmpStanding"'));

        // Check a standing log, then the year is read-only for a supervisor next year; admins can still edit.
        $cap = ComplianceItem::where('title', 'CAPA / deviation log maintained (123.6(g))')->first();
        $this->save($this->op, $cap, ['done' => 1], '2026-01-01', 'annual')->assertRedirect();
        $this->actingAs($this->op)->get('/compliance/annual')->assertSee('1 of 5 done');
        $this->save($this->leo, $cap, ['done' => 1], '2025-01-01', 'annual')->assertForbidden();
        $this->save($this->andy, $cap, ['done' => 1], '2025-01-01', 'annual')->assertRedirect();
        $this->save($this->op, $cap, ['done' => 1], '2026-02-01', 'annual')->assertNotFound();
        $qi = ComplianceItem::where('frequency', 'quarterly')->first();
        $this->save($this->op, $qi, ['done' => 1, 'assignee_user_id' => $this->jack->id], '2026-10-01', 'quarterly')->assertSessionHasErrors('assignee_user_id');
        $h = $this->actingAs($this->op)->get('/compliance/history')->assertOk()->getContent();
        foreach (['hist-quarterly', 'hist-annual', 'Year 2025', 'Q3 2026'] as $x) {
            $this->assertStringContainsString($x, $h);
        }
        $this->actingAs($this->op)->get('/compliance/weekly')->assertSee('Quarterly')->assertSee('Annual');
    }

    public function test_nov18_migration_renames_in_place_and_is_idempotent(): void
    {
        // Simulate live before Nov 18: old title with an entry in history.
        $row = ComplianceItem::where('title', 'Weekly 10% cycle count (SOP-INV-03)')->first();
        $row->update(['title' => 'Physical vs Metrc spot check', 'description' => 'old']);
        ComplianceEntry::create(['item_id' => $row->id, 'period_start' => '2026-11-09', 'done' => true, 'completed_by_user_id' => $this->sae->id, 'completed_on' => '2026-11-12']);
        $before = ComplianceItem::count();
        $m = require database_path('migrations/2026_11_18_001000_compliance_quarterly_annual_items.php');
        $m->up();
        $m->up();
        $row->refresh();
        $this->assertSame('Weekly 10% cycle count (SOP-INV-03)', $row->title);
        $this->assertStringStartsWith('Count at least 10% of active packages', $row->description);
        $this->assertSame($before, ComplianceItem::count());
        $this->assertSame(1, ComplianceEntry::where('item_id', $row->id)->count());
        $this->actingAs($this->andy)->get('/compliance/weekly?period=2026-11-09')->assertSee('Weekly 10% cycle count (SOP-INV-03)')->assertSee('1 of 15 done');
        $this->assertTrue(AuditLog::where('record_type', 'compliance_item')->where('record_id', $row->id)->where('action', 'update')->exists());
    }

    public function test_preview_sample_refuses_on_live_and_seeds_on_preview(): void
    {
        try {
            (new ComplianceSampleSeeder)->run();
            $this->fail('should refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('only runs on the preview site', $e->getMessage());
        }
        $this->assertSame(0, ComplianceEntry::count());
        app()['env'] = 'staging';
        config(['pbp.preview' => true]);
        ComplianceSampleSeeder::seed();
        ComplianceSampleSeeder::seed();   // idempotent
        $this->assertSame(2, Compliance::progress('weekly', Compliance::periodStart('weekly'))['done']);
        $this->assertSame(11, Compliance::progress('weekly', Compliance::periodStart('weekly')->subWeek())['done']);
        $this->assertSame(1, Compliance::progress('monthly', Compliance::periodStart('monthly'))['done']);
        $this->assertSame(4, User::where('email', 'like', '%.sample@preview.invalid')->count());
        $this->assertSame(User::where('email', 'leo.sample@preview.invalid')->value('id'), ComplianceEntry::where('note', 'like', 'Two deliveries%')->value('completed_by_user_id'));
        $this->assertFalse(ComplianceEntry::whereIn('completed_by_user_id', [$this->jack->id])->orWhereIn('assignee_user_id', [$this->jack->id])->exists());
    }
}
