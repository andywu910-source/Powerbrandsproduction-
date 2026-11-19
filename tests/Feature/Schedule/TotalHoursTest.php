<?php

namespace Tests\Feature\Schedule;

use App\Models\ScheduleCell;
use App\Models\ScheduleEmployee;
use App\Models\ScheduleRoster;
use App\Models\ScheduleWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

/**
 * "Total hours" column (Nov 19). The schedule stores one status per person per weekday (no start/end times, no
 * breaks, no clock-ins), so the total is SCHEDULED hours: Working = that week's day length, Partial = its hours,
 * Off = 0, Monday to Friday of the displayed week only. Blank = working (Nov 19): a day nobody marked counts as Working.
 */
class TotalHoursTest extends TestCase
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
        $this->sam = ScheduleEmployee::create(['name' => 'Sam', 'default_hours' => 7.75, 'sort_order' => 3]);
        $this->actingAs($this->andy)->post('/schedule/start', ['week' => '2026-11-16', 'copy' => 0])->assertSessionHasNoErrors();
        $this->w = ScheduleWeek::whereDate('week_start', '2026-11-16')->firstOrFail();
    }

    private function set(ScheduleEmployee $e, string $day, ?string $status, ?float $hours = null): void
    {
        $this->actingAs($this->andy)->post("/schedule/w/{$this->w->id}/p/{$e->id}/$day", array_filter(['status' => $status, 'hours' => $hours], fn ($v) => $v !== null))
            ->assertSessionHasNoErrors();
    }

    /** name => text of that person's Total hours cell on the page. */
    private function totals(string $week = '2026-11-16'): array
    {
        $html = $this->actingAs($this->op)->get('/schedule?week='.$week)->assertOk()->getContent();
        preg_match_all('#<th class="sch-person" scope="row"><b>([^<]+)</b>.*?<td class="sch-total"[^>]*>([^<]+)</td>#s', $html, $m);

        return array_combine($m[1], $m[2]);
    }

    public function test_column_header_says_scheduled_and_sits_at_the_end_of_the_row(): void
    {
        $html = $this->actingAs($this->op)->get('/schedule?week=2026-11-16')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<th class="sch-total" title="Scheduled hours[^"]*no clock-in data[^"]*">Total hours <span class="sch-total-note">scheduled</span></th></tr></thead>#', $html);
        $this->assertSame(['Keith' => '42.5 h', 'Dorothy' => '32 h', 'Sam' => '38.75 h'], $this->totals(), 'nothing planned yet = everyone working (Dorothy off Fridays)');
        config(['pbp.schedule.show_labor' => true]);   // still the last column when labor dollars are shown
        $this->assertMatchesRegularExpression('#Labor \$</th>\s*<th class="sch-total"#', $this->actingAs($this->op)->get('/schedule?week=2026-11-16')->getContent());
    }

    public function test_working_partial_and_off_days_add_up(): void
    {
        foreach (['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19'] as $d) {
            $this->set($this->keith, $d, 'working');            // 4 x 8.5
        }
        $this->set($this->keith, '2026-11-20', 'partial', 4.5);  // + 4.5 = 38.5
        $this->set($this->sam, '2026-11-16', 'working');         // 7.75
        $this->set($this->sam, '2026-11-17', 'partial', 0.25);   // 8
        // Sam: Mon 7.75 + Tue 0.25 + Wed-Fri blank = working 3 x 7.75 = 31.25
        $this->assertSame(['Keith' => '38.5 h', 'Dorothy' => '32 h', 'Sam' => '31.25 h'], $this->totals());
    }

    public function test_time_off_counts_nothing(): void
    {
        foreach (['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19'] as $d) {
            $this->set($this->dot, $d, 'working');
        }
        // Friday is Dorothy's recurring day off (filled in when the week was set up): 0 h.
        $fri = ScheduleCell::where('employee_id', $this->dot->id)->whereDate('day', '2026-11-20')->firstOrFail();
        $this->assertSame('off', $fri->status);
        $this->assertSame('32 h', $this->totals()['Dorothy']);
        $this->set($this->dot, '2026-11-18', 'off');             // a day off mid-week
        $this->assertSame('24 h', $this->totals()['Dorothy']);
        $this->set($this->dot, '2026-11-17', null);              // cleared = blank = working again
        $this->assertSame('24 h', $this->totals()['Dorothy']);
    }

    public function test_the_day_length_is_this_weeks_and_changes_apply(): void
    {
        $this->set($this->keith, '2026-11-16', 'working');
        $this->set($this->keith, '2026-11-17', 'off');
        $this->assertSame('34 h', $this->totals()['Keith']);   // 4 working (1 marked + 3 blank) x 8.5
        ScheduleRoster::where('week_id', $this->w->id)->where('employee_id', $this->keith->id)->firstOrFail()->update(['daily_hours' => 10]);
        $this->assertSame('40 h', $this->totals()['Keith']);
    }

    public function test_only_monday_to_friday_of_the_displayed_week_count(): void
    {
        foreach (['2026-11-17', '2026-11-18', '2026-11-19'] as $d) {
            $this->set($this->keith, $d, 'off');                                     // Mon + Fri left blank = working
        }
        // Saturday / Sunday can't be planned through the app...
        $this->actingAs($this->andy)->post("/schedule/w/{$this->w->id}/p/{$this->keith->id}/2026-11-21", ['status' => 'working'])->assertSessionHasErrors();
        // ...and even a stray weekend or other-week row in the data is not counted.
        ScheduleCell::create(['week_id' => $this->w->id, 'employee_id' => $this->keith->id, 'day' => '2026-11-22', 'status' => 'working']);
        ScheduleCell::create(['week_id' => $this->w->id, 'employee_id' => $this->keith->id, 'day' => '2026-11-13', 'status' => 'partial', 'hours' => 6]);
        $this->assertSame('17 h', $this->totals()['Keith']);

        // The next week is its own total.
        $this->actingAs($this->andy)->post('/schedule/start', ['week' => '2026-11-23', 'copy' => 1])->assertSessionHasNoErrors();
        $next = ScheduleWeek::whereDate('week_start', '2026-11-23')->firstOrFail();
        $this->actingAs($this->andy)->post("/schedule/w/{$next->id}/p/{$this->keith->id}/2026-11-23", ['status' => 'partial', 'hours' => 3])->assertSessionHasNoErrors();
        $this->assertSame('37 h', $this->totals('2026-11-25')['Keith']);   // 3 + 4 blank days x 8.5; any day opens its week
        $this->assertSame('17 h', $this->totals()['Keith']);
    }

    public function test_people_taken_off_the_week_have_no_row(): void
    {
        $this->set($this->sam, '2026-11-16', 'working');
        ScheduleRoster::where('week_id', $this->w->id)->where('employee_id', $this->sam->id)->firstOrFail()->update(['removed_at' => now()]);
        $this->assertArrayNotHasKey('Sam', $this->totals());
    }
}
