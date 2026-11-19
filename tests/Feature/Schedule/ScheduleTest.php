<?php

namespace Tests\Feature\Schedule;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\ScheduleCell;
use App\Models\ScheduleEmployee;
use App\Models\ScheduleJob;
use App\Models\ScheduleNote;
use App\Models\ScheduleWeek;
use App\Models\Setting;
use App\Models\User;
use App\Services\ScheduleService;
use App\Support\Schedule;
use Carbon\CarbonImmutable;
use Database\Seeders\ScheduleSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private const W = '2026-11-16';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,schedule']);
    }

    private function people(): array
    {
        $a = ScheduleEmployee::create(['name' => 'Keith', 'role' => 'Production', 'default_hours' => 8.5, 'sort_order' => 1]);
        $b = ScheduleEmployee::create(['name' => 'Dorothy', 'role' => 'Production', 'default_hours' => 8, 'recurring_off' => [5], 'sort_order' => 2]);
        $c = ScheduleEmployee::create(['name' => 'Gone', 'default_hours' => 8, 'active' => false, 'sort_order' => 3]);

        return [$a, $b, $c];
    }

    private function startWeek(string $w = self::W, bool $copy = false)
    {
        return $this->actingAs($this->andy)->post('/schedule/start', ['week' => $w, 'copy' => $copy ? 1 : 0]);
    }

    private function week(string $w = self::W): ScheduleWeek
    {
        return ScheduleWeek::whereDate('week_start', $w)->firstOrFail();
    }

    public function test_tab_is_on_whatever_the_env_format_and_can_be_switched_off(): void
    {
        foreach (['production,receiving,checklists,batchlog,labels', '"production, batchlog, labels"', "'production,labels'", 'production labels', '', 'production,schedule,schedule'] as $v) {
            config(['pbp.modules' => $v]);
            $this->assertTrue(\App\Support\Modules::enabled('schedule'), "schedule on with PBP_MODULES=[$v]");
        }
        config(['pbp.modules' => '"Production, BatchLog"']);
        $this->assertSame(['production', 'batchlog', 'schedule', 'compliance'], \App\Support\Modules::list(), 'quotes, spaces and case are ignored');
        $this->actingAs($this->andy)->get('/dashboard')->assertOk()->assertSee('<span class="nav-text">Schedule</span>', false);
        config(['pbp.modules_off' => 'schedule']);
        $this->actingAs($this->andy)->get('/schedule')->assertNotFound();
        $this->actingAs($this->andy)->get('/dashboard')->assertOk()->assertDontSee('>Schedule<', false);
        config(['pbp.modules_off' => '', 'pbp.modules_release_on' => [], 'pbp.modules' => 'production,receiving,checklists,batchlog']);
        $this->assertFalse(\App\Support\Modules::enabled('schedule'));
    }

    public function test_who_can_edit(): void
    {
        $jack = User::factory()->create(['name' => 'Jack']);
        $this->assertTrue(Schedule::canEdit($this->andy));     // role:admin
        $this->assertTrue(Schedule::canEdit($this->jenny));    // name:Jenny
        $this->assertTrue(Schedule::canEdit($this->leo));      // name:Leo
        $this->assertTrue(Schedule::canEdit($jack));           // name:Jack
        $this->assertFalse(Schedule::canEdit($this->op));
        $this->assertFalse(Schedule::canEdit($this->viewer));
        $this->people();
        $this->actingAs($this->op)->post('/schedule/start', ['week' => self::W])->assertForbidden();
        $this->actingAs($this->op)->get('/schedule?week='.self::W)->assertOk()->assertDontSee('Start from the people list');
        // the list is easy to change, and only admins may change it
        $this->actingAs($this->leo)->post('/schedule/setup', ['editors' => 'role:admin'])->assertForbidden();
        $this->actingAs($this->andy)->post('/schedule/setup', ['editors' => "role:admin\nname:Maria Operator"])->assertRedirect();
        Setting::flush();
        $this->assertTrue(Schedule::canEdit($this->op));
        $this->assertFalse(Schedule::canEdit($this->jenny));
        $this->actingAs($this->andy)->post('/schedule/setup', ['editors' => 'name:Nobody'])->assertRedirect();
        Setting::flush();
        $this->assertTrue(Schedule::canEdit($this->andy), 'an admin cannot lock themselves out');
    }

    public function test_week_hours_count_only_scheduled_days(): void
    {
        [$keith, $dot] = $this->people();
        $this->startWeek()->assertRedirect()->assertSessionHasNoErrors();
        $w = $this->week();
        $this->assertSame(2, $w->roster()->count(), 'inactive people are not put on a week');
        $fri = ScheduleCell::where('employee_id', $dot->id)->whereDate('day', '2026-11-20')->firstOrFail();
        $this->assertSame(['off', true], [$fri->status, $fri->from_recurring]);

        $grid = fn () => collect(app(ScheduleService::class)->grid($w->fresh())['rows'])->keyBy(fn ($r) => $r['employee']->name);
        $this->assertSame(42.5, $grid()['Keith']['hours'], 'blank = working (Nov 19): 5 x 8.5');

        $u = "/schedule/w/{$w->id}/p/{$keith->id}/";
        $this->actingAs($this->andy)->post($u.'2026-11-16', ['status' => 'working', 'note' => 'static boro'])->assertSessionHasNoErrors();
        $this->actingAs($this->andy)->post($u.'2026-11-17', ['status' => 'partial', 'hours' => 5])->assertSessionHasNoErrors();
        $this->actingAs($this->andy)->post($u.'2026-11-18', ['status' => 'off'])->assertSessionHasNoErrors();
        $this->actingAs($this->andy)->post($u.'2026-11-19', ['status' => 'partial'])->assertSessionHasErrors('hours');
        $this->actingAs($this->andy)->post($u.'2026-11-21', ['status' => 'working'])->assertSessionHasErrors();   // Saturday is not on the week
        $this->assertFalse(ScheduleCell::whereDate('day', '2026-11-21')->exists());
        $this->assertSame(30.5, $grid()['Keith']['hours'], '8.5 + 5 + off + 2 blank x 8.5');
        $this->assertSame(32.0, $grid()['Dorothy']['hours'], 'Mon-Thu blank = working, Friday recurring off');

        $page = $this->actingAs($this->op)->get('/schedule?week=2026-11-18')->assertOk()->getContent();   // any day of the week opens that week
        $this->assertStringContainsString('Partial · 5 h', $page);
        $this->assertStringNotContainsString('Off every Friday', $page, 'no description under the names');
        $this->assertStringNotContainsString('h day<', $page);
        $this->assertStringNotContainsString('<th class="sch-hours">Hours</th>', $page, 'the old hours column stays gone');
        $this->assertStringContainsString('Total hours <span class="sch-total-note">scheduled</span>', $page, 'Total hours (scheduled) column, Nov 19');
        $this->assertStringNotContainsString('<tfoot>', $page);
        $this->assertStringNotContainsString('Labor $', $page, 'labor dollars are hidden by default');
        config(['pbp.schedule.show_labor' => true]);
        $this->assertStringContainsString('$610', $this->actingAs($this->op)->get('/schedule?week='.self::W)->getContent());   // 30.5 h x $20 (blank = working)
        $this->assertTrue(AuditLog::where('record_type', 'schedule_cell')->exists());
    }

    public function test_copy_last_week(): void
    {
        [$keith, $dot, $gone] = $this->people();
        $this->startWeek()->assertRedirect();
        $w = $this->week();
        app(ScheduleService::class)->addToRoster($w, $gone);
        $gone->update(['active' => false]);
        $keith->update(['default_hours' => 9]);
        $cell = ScheduleCell::create(['week_id' => $w->id, 'employee_id' => $keith->id, 'day' => self::W, 'status' => 'working']);
        ScheduleJob::create(['cell_id' => $cell->id, 'product_id' => $this->product->id]);
        ScheduleNote::create(['week_id' => $w->id, 'section' => 'IN LAB', 'text' => 'x']);

        $this->actingAs($this->andy)->get('/schedule?week=2026-11-23')->assertOk()->assertSee('Copy last week');
        $this->actingAs($this->andy)->get('/schedule/copy?week=2026-11-23')->assertOk()
            ->assertSee('Keith')->assertSee('9 h')->assertSee('Off every Friday')->assertSee('Not copied: inactive');
        $this->actingAs($this->andy)->post('/schedule/start', ['week' => '2026-11-23', 'copy' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $n = $this->week('2026-11-23');
        $this->assertSame($w->id, $n->copied_from_id);
        $this->assertSame(['Keith', 'Dorothy'], $n->roster()->with('employee')->get()->map(fn ($r) => $r->employee->name)->all());
        $this->assertSame(9.0, $n->roster()->where('employee_id', $keith->id)->first()->daily_hours);
        $this->assertSame(['2026-11-27'], ScheduleCell::where('week_id', $n->id)->get()->map(fn ($c) => $c->day->toDateString())->all(), 'only the recurring Friday off');
        $this->assertSame(0, ScheduleNote::where('week_id', $n->id)->count());
        $this->actingAs($this->andy)->post('/schedule/start', ['week' => '2026-11-23', 'copy' => 1])->assertSessionHasErrors();
    }

    private function incoming(string $n): Batch
    {
        return app(\App\Services\ProductionWorkflow::class)->createIncoming($this->product->fresh(), $this->andy, ['batch_number' => $n, 'planned_units' => 500]);
    }

    public function test_plan_is_one_line_batch_search_without_finished(): void
    {
        [$keith] = $this->people();
        $this->startWeek();
        $w = $this->week();
        $inc = $this->incoming('INC-1');
        $prod = $this->startBatch(120, 'SCH-001');
        $done = $this->startBatch(120, 'DONE-1');
        $done->update(['log_status' => 'finished']);
        $u = "/schedule/w/{$w->id}/p/{$keith->id}/2026-11-16";
        $page = $this->actingAs($this->andy)->get($u)->assertOk()->getContent();
        $this->assertStringContainsString('id="jobBatch"', $page);
        $this->assertStringContainsString('<option value="INC-1 — ', $page);
        $this->assertStringContainsString('<option value="SCH-001 — ', $page);
        $this->assertStringNotContainsString('<option value="DONE-1', $page, 'Finished batches are not offered');
        $this->assertStringNotContainsString('id="jp-brand"', $page);
        $this->assertStringNotContainsString('target_qty', $page);

        $this->actingAs($this->andy)->post($u.'/jobs', ['batch' => ''])->assertSessionHasErrors('batch');
        $this->actingAs($this->andy)->post($u.'/jobs', ['batch' => 'DONE-1'])->assertSessionHasErrors('batch');
        $this->actingAs($this->andy)->post($u.'/jobs', ['batch' => $this->product->name])->assertSessionHasErrors('batch');   // 2 open batches of it: ambiguous
        $this->actingAs($this->andy)->post($u.'/jobs', ['batch' => 'sch-001 — whatever', 'process_step' => 'Fill'])->assertSessionHasNoErrors();
        $job = ScheduleJob::firstOrFail();
        $this->assertSame([$prod->id, $this->product->id, 'working'], [$job->batch_id, $job->product_id, $job->cell->status]);
        $this->actingAs($this->op)->post($u.'/jobs', ['batch' => 'INC-1'])->assertForbidden();

        $grid = $this->actingAs($this->op)->get('/schedule?week='.self::W)->assertOk()->getContent();
        $this->assertStringContainsString('SCH-001', $grid);
        $this->assertStringContainsString('lst lst-in_production', $grid);
        $prod->update(['log_status' => 'finished']);
        $this->actingAs($this->op)->get('/schedule?week='.self::W)->assertSee('Finished');
        // the job edit keeps the batch link; removing keeps the row
        $this->actingAs($this->andy)->post("/schedule/jobs/{$job->id}", ['batch_number' => 'nope'])->assertSessionHasErrors('batch_number');
        $this->actingAs($this->andy)->post("/schedule/jobs/{$job->id}/remove")->assertRedirect();
        $this->assertNotNull(ScheduleJob::find($job->id)->removed_at);
    }

    public function test_going_to_lab_in_lab_and_pending(): void
    {
        [$keith] = $this->people();
        $this->startWeek();
        $w = $this->week();
        $inc = $this->incoming('INC-7');
        $lab = $this->startBatch(120, 'LAB-1');
        $lab->update(['log_status' => 'in_lab']);
        $page = $this->actingAs($this->andy)->get('/schedule?week='.self::W)->assertOk()->getContent();
        foreach (['Going to lab', 'In lab', 'Pending'] as $h) {
            $this->assertStringContainsString("<h3>$h</h3>", $page);
        }
        foreach (['What the team is doing', 'What we need', 'Work List', 'Week notes'] as $h) {
            $this->assertStringNotContainsString($h, $page);
        }
        // Going to lab: only Incoming jobs are offered
        preg_match('~<form[^>]*id="addLab".*?</form>~s', $page, $m);
        $this->assertStringContainsString('value="INC-7"', $m[0]);
        $this->assertStringNotContainsString('LAB-1', $m[0]);
        $notes = "/schedule/w/{$w->id}/notes";
        $this->actingAs($this->andy)->post($notes, ['section' => 'Going to lab', 'batch' => 'LAB-1'])->assertSessionHasErrors('lab_batch');
        $this->actingAs($this->andy)->post($notes, ['section' => 'Going to lab', 'batch' => 'INC-7'])->assertRedirect();
        $this->actingAs($this->andy)->post($notes, ['section' => 'What we need', 'text' => 'x'])->assertSessionHasErrors('section');
        $this->assertSame('incoming', $inc->fresh()->log_status, 'adding to Going to lab does not change the status');
        // In lab lists itself
        $page = $this->actingAs($this->viewer)->get('/schedule?week='.self::W)->getContent();
        preg_match('~id="goingToLab".*?</table>~s', $page, $g);
        preg_match('~id="inLabTable".*?</table>~s', $page, $l);
        $this->assertStringContainsString('INC-7', $g[0]);
        $this->assertStringContainsString('lst lst-incoming', $g[0]);
        $this->assertStringContainsString('LAB-1', $l[0]);
        $this->assertStringContainsString('lst lst-in_lab', $l[0]);
        // the checkbox: Incoming -> In lab (audited), stays on Going to lab ticked; unticking puts it back
        $gl = ScheduleNote::where('section', 'Going to lab')->firstOrFail();
        $this->actingAs($this->op)->post("/schedule/notes/{$gl->id}", ['lab_done' => 1])->assertForbidden();
        $this->actingAs($this->andy)->post("/schedule/notes/{$gl->id}", ['lab_done' => 1])->assertRedirect();
        $this->assertSame(['in_lab', true], [$inc->fresh()->log_status, $gl->fresh()->done]);
        $this->assertTrue(AuditLog::where('action', 'batch_log_status')->get()->contains(fn ($a) => str_contains(json_encode($a->details ?? $a->toArray()), 'schedule_going_to_lab')));
        $page = $this->actingAs($this->viewer)->get('/schedule?week='.self::W)->getContent();
        preg_match('~id="goingToLab".*?</table>~s', $page, $g);
        preg_match('~id="inLabTable".*?</table>~s', $page, $l);
        $this->assertStringContainsString('INC-7', $g[0]);
        $this->assertStringContainsString('✓', $g[0]);
        $this->assertStringContainsString('INC-7', $l[0]);
        $this->actingAs($this->andy)->post("/schedule/notes/{$gl->id}", ['lab_done' => 0])->assertRedirect();
        $this->assertSame('incoming', $inc->fresh()->log_status);
        // Pending: [batch #] - note, add / edit / remove
        $this->actingAs($this->andy)->post($notes, ['section' => 'Pending', 'batch' => 'INC-7 — x', 'text' => 'waiting for apollo labels'])->assertRedirect();
        $n = ScheduleNote::where('section', 'Pending')->firstOrFail();
        $this->assertSame([$inc->id, 'INC-7 - waiting for apollo labels'], [$n->batch_id, $n->line()]);
        $this->actingAs($this->viewer)->get('/schedule?week='.self::W)->assertSee('INC-7 - waiting for apollo labels');
        $this->actingAs($this->andy)->post($notes, ['section' => 'Pending', 'batch' => 'nope', 'text' => 'x'])->assertSessionHasErrors('pending_batch');
        $this->actingAs($this->andy)->post($notes, ['section' => 'Pending', 'batch' => '', 'text' => 'call the lab'])->assertRedirect();
        $this->actingAs($this->andy)->post("/schedule/notes/{$n->id}", ['text' => 'labels came'])->assertRedirect();
        $this->assertSame('labels came', $n->fresh()->text);
        $this->actingAs($this->op)->post("/schedule/notes/{$n->id}", ['remove' => 1])->assertForbidden();
        ScheduleCell::create(['week_id' => $w->id, 'employee_id' => $keith->id, 'day' => '2026-11-17', 'status' => 'working', 'note' => 'grinding']);
        $this->actingAs($this->viewer)->get('/schedule/today?day=2026-11-17')->assertOk()->assertSee('Keith')->assertSee('grinding')->assertSee('INC-7 - labels came');
        $this->actingAs($this->andy)->post("/schedule/notes/{$n->id}", ['remove' => 1])->assertRedirect();
        $this->assertNotNull(ScheduleNote::find($n->id)->removed_at);
        $this->assertTrue(AuditLog::where('record_type', 'schedule_note')->exists());
    }

    public function test_schedule_job_saved_as_incoming_links_and_shows_colour(): void
    {
        [$keith] = $this->people();
        $this->startWeek();
        $w = $this->week();
        $cell = ScheduleCell::create(['week_id' => $w->id, 'employee_id' => $keith->id, 'day' => '2026-11-18', 'status' => 'working']);
        $job = ScheduleJob::create(['cell_id' => $cell->id, 'product_id' => $this->product->id, 'target_qty' => 300]);   // older jobs without a batch
        $this->actingAs($this->andy)->get(route('production.index', ['product_id' => $this->product->id, 'schedule_job' => $job->id]))
            ->assertOk()->assertSee('value="2026-11-18"', false)->assertSee('value="300"', false);
        $this->actingAs($this->andy)->post('/production/incoming', ['product_id' => $this->product->id, 'batch_number' => 'SCH-INC-1',
            'planned_units' => 300, 'schedule_job' => $job->id])->assertSessionHasNoErrors()->assertRedirect();
        $b = Batch::where('batch_number', 'SCH-INC-1')->firstOrFail();
        $this->assertSame(['planned', 'incoming', $b->id], [$b->status, $b->log_status, $job->fresh()->batch_id]);
        $this->actingAs($this->op)->get('/schedule?week='.self::W)->assertSee('SCH-INC-1')->assertSee('lst lst-incoming', false)->assertSee('Incoming');
    }

    public function test_live_seed_is_the_sheet_roster_and_week_only(): void
    {
        $this->startBatch(120, 'LIVE-1');
        [$batches, $users] = [Batch::count(), User::count()];
        $m = require database_path('migrations/2026_11_17_006300_seed_schedule_from_sheet.php');
        $m->up();
        $this->assertSame(0, ScheduleEmployee::count(), 'skipped in the test suite unless forced');
        $m->force = true;
        $m->up();
        $m->up();   // idempotent
        $this->assertSame(12, ScheduleEmployee::count());
        $this->assertSame(1, ScheduleWeek::count());
        $this->assertSame(0, ScheduleNote::count(), 'no notes on live');
        $this->assertSame(0, ScheduleJob::count());
        $this->assertSame([$batches, $users], [Batch::count(), User::count()], 'no batches or users touched');
        $this->assertSame(0, Batch::where('batch_number', 'like', 'TEST-%')->count());
        $this->assertSame(['Angelo', 'Brian (big guy)', 'Jay', 'Jeff', 'Joseph', 'Dorthy', 'Keith', 'Manny', 'Sae', 'Jenny', 'Jack', 'Leo'],
            ScheduleWeek::firstOrFail()->roster()->with('employee')->get()->map(fn ($r) => $r->employee->name)->all(), 'in Andy order');
        $page = $this->actingAs($this->op)->get('/schedule?week='.self::W)->assertOk()->getContent();
        $this->assertStringContainsString('Dorthy', $page);
        $this->assertStringNotContainsString('Billing / compliance', $page, 'no descriptions under names');
        $this->assertStringContainsString('css/schedule.css', $page);
        $this->assertSame('df7ba8a9b435fcc589b4fc22a032ae11874e2384dbdbc2201befeabec16f5b9b', hash_file('sha256', public_path('css/pbp.css')), 'pbp.css is the colour-hotfixed file');
        $this->assertSame('e40af3c4186453692b276bc9975db661f2855a1b4b5519ec975498f00edf589c', hash_file('sha256', app_path('Models/Batch.php')), 'Batch.php is the colour-hotfixed file');
    }

    public function test_people_setup(): void
    {
        $this->actingAs($this->andy)->post('/schedule/people', ['name' => 'Dorothy', 'role' => 'Production', 'default_hours' => 8, 'recurring_off' => [5], 'active' => 1])->assertRedirect();
        $e = ScheduleEmployee::where('name', 'Dorothy')->firstOrFail();
        $this->assertSame([5], $e->recurring_off);
        $this->actingAs($this->op)->post('/schedule/people', ['name' => 'X', 'default_hours' => 8])->assertForbidden();
        $this->actingAs($this->viewer)->get('/schedule/people')->assertOk()->assertSee('Off every Friday')->assertDontSee('Add a person');
    }

    public function test_preview_sample_week_from_the_sheet(): void
    {
        $this->expectExceptionMessage('only runs on the preview site');
        try {
            (new ScheduleSampleSeeder)->run();
        } finally {
            $this->assertSame(0, ScheduleWeek::count());
            $this->assertSame(0, Batch::where('batch_number', 'like', 'TEST-%')->count());
            app()['env'] = 'staging';
            config(['pbp.preview' => true]);
            ScheduleSampleSeeder::seed();
            ScheduleSampleSeeder::seed();   // safe to run again
            $c = Batch::where('batch_number', 'like', 'TEST-%')->selectRaw('log_status, count(*) c')->groupBy('log_status')->pluck('c', 'log_status')->map(fn ($c) => (int) $c)->all();
            ksort($c);
            $this->assertSame(['in_lab' => 3, 'in_production' => 3, 'incoming' => 4], $c);
            $this->assertSame([false, false, true], ScheduleNote::where('section', 'Going to lab')->orderBy('id')->pluck('done')->map(fn ($d) => (bool) $d)->all());
            $this->assertSame('TEST-002 - waiting for apollo labels', ScheduleNote::where('section', 'Pending')->orderBy('id')->first()->line());
            $this->assertSame(1, ScheduleWeek::count());
            $rows = collect(app(ScheduleService::class)->grid($this->week())['rows'])->keyBy(fn ($r) => $r['employee']->name);
            $this->assertSame(12, $rows->count());
            $this->assertSame([25.5, 42.5, 23.0, 40.0, 40.0], [$rows['Keith']['hours'], $rows['Jeff']['hours'], $rows['Dorthy']['hours'], $rows['Manny']['hours'], $rows['Jenny']['hours']]);
            $this->assertSame('off', ScheduleCell::where('employee_id', $rows['Dorthy']['employee']->id)->whereDate('day', '2026-11-20')->value('status'));
            $this->assertSame(6, ScheduleNote::count());   // 3 Going to lab + 3 Pending
        }
    }
}
