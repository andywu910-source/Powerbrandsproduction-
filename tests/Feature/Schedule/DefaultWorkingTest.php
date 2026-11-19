<?php

namespace Tests\Feature\Schedule;

use App\Models\AuditLog;
use App\Models\ScheduleCell;
use App\Models\ScheduleEmployee;
use App\Models\ScheduleJob;
use App\Models\ScheduleWeek;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

/**
 * Blank = working (Nov 19): a weekday nobody marked counts as Working at the week's day length; only Off / Partial
 * are marked. Bulk All working / All off for a person's week or for everyone on one day.
 */
class DefaultWorkingTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private ScheduleWeek $w;
    private ScheduleEmployee $keith, $dot, $sam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPlant();
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,schedule']);
        $this->keith = ScheduleEmployee::create(['name' => 'Keith', 'default_hours' => 8.5, 'sort_order' => 1]);
        $this->dot = ScheduleEmployee::create(['name' => 'Dorothy', 'default_hours' => 8, 'recurring_off' => [5], 'sort_order' => 2]);
        $this->sam = ScheduleEmployee::create(['name' => 'Sam', 'default_hours' => 7.75, 'sort_order' => 3, 'active' => true]);
        $this->actingAs($this->andy)->post('/schedule/start', ['week' => '2026-11-16', 'copy' => 0])->assertSessionHasNoErrors();
        $this->w = ScheduleWeek::whereDate('week_start', '2026-11-16')->firstOrFail();
    }

    private function hours(): array
    {
        return collect(app(ScheduleService::class)->grid($this->w->fresh())['rows'])->mapWithKeys(fn ($r) => [$r['employee']->name => $r['hours']])->all();
    }

    private function cellStatus(ScheduleEmployee $e, string $day): ?string
    {
        return ScheduleCell::where('employee_id', $e->id)->whereDate('day', $day)->value('status');
    }

    public function test_blank_days_count_as_working_and_saved_data_is_not_rewritten(): void
    {
        $before = ScheduleCell::count();
        $this->assertSame(['Keith' => 42.5, 'Dorothy' => 32.0, 'Sam' => 38.75], $this->hours());
        $this->assertSame($before, ScheduleCell::count(), 'reading never writes cells');
        $this->assertSame(1, $before, 'only the recurring Friday off was stored');

        // a cell that exists with no status (e.g. a note only) is still working
        ScheduleCell::create(['week_id' => $this->w->id, 'employee_id' => $this->keith->id, 'day' => '2026-11-18', 'status' => null, 'note' => 'came in at 10']);
        $this->assertSame(42.5, $this->hours()['Keith']);

        $this->assertSame('working', ScheduleCell::effective(null));
        $this->assertTrue(ScheduleCell::isDefault(null));
        $this->assertSame(8.0, ScheduleCell::hoursFor(null, 8));
    }

    public function test_recurring_off_still_counts_as_off_and_the_page_says_blank_is_working(): void
    {
        $this->assertSame('off', $this->cellStatus($this->dot, '2026-11-20'));
        $html = $this->actingAs($this->op)->get('/schedule?week=2026-11-16')->assertOk()->getContent();
        $this->assertStringContainsString('<b>Blank = working.</b>', $html);
        $this->assertSame(14, substr_count($html, 'sch-chip working is-default'), '15 weekday cells minus Dorothy\'s Friday off');
        $this->assertStringContainsString('Dorothy, Friday: Off"', $html);
        $this->assertStringContainsString('Keith, Monday: Working (blank = working)', $html);
        // today view lists everyone not off as working
        $today = $this->actingAs($this->op)->get('/schedule/today?day=2026-11-20')->assertOk()->getContent();
        $this->assertStringContainsString('2 working · 16.25 h planned', $today);
        $this->assertStringContainsString('<b>Off:</b> Dorothy', $today);
        $this->assertStringNotContainsString('Not scheduled', $today);
    }

    public function test_new_people_added_to_a_week_default_to_working(): void
    {
        $lee = ScheduleEmployee::create(['name' => 'Lee', 'default_hours' => 8, 'recurring_off' => [1], 'sort_order' => 4]);
        $this->actingAs($this->andy)->post("/schedule/w/{$this->w->id}/people", ['employee_id' => $lee->id])->assertSessionHasNoErrors();
        $this->assertSame(32.0, $this->hours()['Lee'], 'Tue-Fri working, recurring Monday off');
    }

    public function test_row_all_off_and_all_working(): void
    {
        ScheduleCell::create(['week_id' => $this->w->id, 'employee_id' => $this->keith->id, 'day' => '2026-11-17', 'status' => 'partial', 'hours' => 4, 'note' => 'dentist']);
        $url = "/schedule/w/{$this->w->id}/p/{$this->keith->id}/all";
        $this->actingAs($this->andy)->post($url, ['status' => 'off'])->assertRedirect('/schedule?week=2026-11-16')->assertSessionHas('status', 'Keith: all week off (5 days changed).');
        $this->assertSame(0.0, $this->hours()['Keith']);
        $this->assertSame('dentist', ScheduleCell::where('employee_id', $this->keith->id)->whereDate('day', '2026-11-17')->value('note'), 'notes stay');
        $this->assertSame(['Dorothy' => 32.0, 'Sam' => 38.75], array_diff_key($this->hours(), ['Keith' => 1]), 'other rows untouched');

        $this->actingAs($this->andy)->post($url, ['status' => 'off'])->assertSessionHas('status', 'Keith: all week off (0 days changed).');
        $this->actingAs($this->andy)->post($url, ['status' => 'working'])->assertSessionHas('status', 'Keith: all week working (5 days changed).');
        $this->assertSame(42.5, $this->hours()['Keith']);
        $this->assertNull(ScheduleCell::where('employee_id', $this->keith->id)->whereDate('day', '2026-11-17')->value('hours'));

        // Dorothy's recurring Friday off is overridden only when someone asks for her whole week working
        $this->actingAs($this->andy)->post("/schedule/w/{$this->w->id}/p/{$this->dot->id}/all", ['status' => 'working'])->assertSessionHas('status', 'Dorothy: all week working (5 days changed).');
        $this->assertSame(40.0, $this->hours()['Dorothy']);
    }

    public function test_day_all_off_for_a_holiday_then_back(): void
    {
        $cell = app(ScheduleService::class)->cell($this->w, $this->sam, \Carbon\CarbonImmutable::parse('2026-11-19'));
        $cell->fill(['status' => 'working'])->save();
        ScheduleJob::create(['cell_id' => $cell->id, 'note' => 'fill run', 'sort_order' => 1]);
        $url = "/schedule/w/{$this->w->id}/d/2026-11-19/all";
        $this->actingAs($this->andy)->post($url, ['status' => 'off'])->assertRedirect('/schedule?week=2026-11-16')->assertSessionHas('status', 'Thursday 11/19: everyone off (3 changed).');
        $this->assertSame(['Keith' => 34.0, 'Dorothy' => 24.0, 'Sam' => 31.0], $this->hours());
        $this->assertSame(1, ScheduleJob::where('cell_id', $cell->id)->count(), 'jobs stay on the day');
        $this->actingAs($this->andy)->post($url, ['status' => 'working'])->assertSessionHas('status', 'Thursday 11/19: everyone working (3 changed).');
        $this->assertSame(['Keith' => 42.5, 'Dorothy' => 32.0, 'Sam' => 38.75], $this->hours());
        $this->assertSame('off', $this->cellStatus($this->dot, '2026-11-20'), 'other days untouched');
    }

    public function test_bulk_changes_are_audited(): void
    {
        $this->actingAs($this->andy)->post("/schedule/w/{$this->w->id}/d/2026-11-20/all", ['status' => 'off']);
        $sum = AuditLog::where('action', 'schedule_bulk')->latest('id')->firstOrFail();
        $this->assertSame([$this->andy->id, 'schedule_week', $this->w->id], [$sum->user_id, $sum->record_type, $sum->record_id]);
        $this->assertSame('day', $sum->details['scope']);
        $this->assertSame('off', $sum->details['status']);
        $this->assertSame(['2026-11-20'], $sum->details['days']);
        $this->assertSame(['Keith', 'Dorothy', 'Sam'], $sum->details['people']);
        $this->assertSame(2, $sum->details['changed'], 'Dorothy was already off');
        $this->assertCount(2, $sum->details['cell_ids']);
        // each changed cell also has its own create/update row (existing convention)
        foreach ($sum->details['cell_ids'] as $id) {
            $this->assertTrue(AuditLog::where('record_type', 'schedule_cell')->where('record_id', $id)->where('user_id', $this->andy->id)->exists());
        }
        $this->actingAs($this->andy)->post("/schedule/w/{$this->w->id}/p/{$this->sam->id}/all", ['status' => 'working']);
        $this->assertSame(['row', ['Sam']], [AuditLog::where('action', 'schedule_bulk')->latest('id')->first()->details['scope'], AuditLog::where('action', 'schedule_bulk')->latest('id')->first()->details['people']]);
    }

    public function test_bulk_guards(): void
    {
        $this->actingAs($this->viewer)->post("/schedule/w/{$this->w->id}/p/{$this->keith->id}/all", ['status' => 'off'])->assertForbidden();
        $this->actingAs($this->viewer)->post("/schedule/w/{$this->w->id}/d/2026-11-18/all", ['status' => 'off'])->assertForbidden();
        $this->actingAs($this->andy)->post("/schedule/w/{$this->w->id}/d/2026-11-21/all", ['status' => 'off'])->assertNotFound();   // Saturday
        $this->actingAs($this->andy)->post("/schedule/w/{$this->w->id}/d/2026-11-23/all", ['status' => 'off'])->assertNotFound();   // next week
        $this->actingAs($this->andy)->post("/schedule/w/{$this->w->id}/d/2026-11-18/all", ['status' => 'partial'])->assertSessionHasErrors('status');
        $lee = ScheduleEmployee::create(['name' => 'Lee', 'default_hours' => 8]);
        $this->actingAs($this->andy)->post("/schedule/w/{$this->w->id}/p/{$lee->id}/all", ['status' => 'off'])->assertNotFound();   // not on this week
        $this->assertSame(0, AuditLog::where('action', 'schedule_bulk')->count());
        // buttons only for editors
        $this->assertStringNotContainsString('sch-bulk-btn', $this->actingAs($this->viewer)->get('/schedule?week=2026-11-16')->getContent());
        $this->assertSame(16, substr_count($this->actingAs($this->andy)->get('/schedule?week=2026-11-16')->getContent(), 'class="sch-bulk-btn'), '3 rows + 5 days, 2 buttons each');
    }
}
