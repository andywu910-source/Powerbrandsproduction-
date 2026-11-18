<?php

namespace Database\Seeders;

use App\Models\ScheduleCell;
use App\Models\ScheduleEmployee;
use App\Models\ScheduleNote;
use App\Models\User;
use App\Services\ScheduleService;
use App\Support\Preview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * PREVIEW ONLY: the week of Mon 11/16/2026 from the SCHEDULE tab of Andy's "Production Log.xlsx"
 * (rows 155-171, read-only copy taken Nov 17). Cell text is kept word for word as the day's note;
 * "off" -> Off; "left at 4" with the sheet's "-1" hours formula -> Partial 7 h. Wed-Fri were empty in
 * the sheet, so they stay "not scheduled" (0 hours) except Dorthy's recurring Friday off.
 * Never runs on the live site.
 */
class ScheduleSampleSeeder extends Seeder
{
    public const WEEK = '2026-11-16';

    /** name, role, default day, recurring days off (1 = Mon ... 5 = Fri) */
    public const PEOPLE = [
        // Andy's order (Nov 17, 3:27 PM): Dorthy after Joseph, before Keith; Jenny, Jack and Leo last.
        ['Angelo', 'Production', 8.5, []], ['Brian (big guy)', 'Production', 8, []], ['Jay', 'Production', 8, []], ['Jeff', 'Production', 8.5, []],
        ['Joseph', 'Production', 8, []], ['Dorthy', 'Production', 8, [5]], ['Keith', 'Production', 8.5, []], ['Manny', 'Production', 8, []],
        ['Sae', 'Production', 8.5, []], ['Jenny', 'Billing / compliance', 8, []], ['Jack', null, 8, []], ['Leo', null, 8, []],
    ];

    /** name => [Mon, Tue] exactly as typed in the sheet ('' = empty) */
    public const CELLS = [
        'Keith' => ['off', 'off'],
        'Jeff' => ['static  boro/', 'Formulate Muha Meds/ working on chemical'],
        'Sae' => ['static  boro/', 'working on equipment'],
        'Angelo' => ['static  boro/ grinding', 'grinding and formulating'],
        'Manny' => ['static  boro/ spreading 50lb', ''],
        'Dorthy' => ['static  boro/ left at 4', 'off'],
        'Jay' => ['static  boro/ grinding', 'gridning and formulating'],
        'Brian (big guy)' => ['static  boro/ left at 4', 'off'],
        'Joseph' => ['static  boro/ grinding', 'gridning and formulating'],
    ];

    /** The sheet subtracts 1 hour on these days (formula "...-1"): partial days. */
    public const PARTIAL = ['Dorthy' => [0 => 7], 'Brian (big guy)' => [0 => 7]];

    /** Clearly fake test batches (preview only): number => [Batch Log status, units]. */
    public const TEST_BATCHES = [
        'TEST-001' => ['incoming', 1200], 'TEST-002' => ['incoming', 2400], 'TEST-003' => ['incoming', 600], 'TEST-004' => ['incoming', null],
        'TEST-005' => ['in_production', 1500], 'TEST-006' => ['in_production', 900], 'TEST-007' => ['in_production', 3000],
        'TEST-008' => ['in_lab', 1152], 'TEST-009' => ['in_lab', 2500], 'TEST-010' => ['incoming', 800],
    ];

    /** Pending notes for the week: [batch number, note]. */
    public const PENDING = [['TEST-002', 'waiting for apollo labels'], ['TEST-006', 'waiting on more distillate'], ['TEST-008', 'lab results due Thursday']];

    /** Picked for "Going to lab". */
    public const GOING_TO_LAB = ['TEST-001' => false, 'TEST-003' => false, 'TEST-010' => true];   // true = ticked (went to lab -> In lab)

    public function run(): void
    {
        if (! Preview::enabled()) {
            throw new \RuntimeException('ScheduleSampleSeeder only runs on the preview site (APP_ENV=staging, PBP_PREVIEW=true).');
        }
        self::seed();
    }

    public static function seed(): void
    {
        $week = self::seedSheet();
        self::seedTestBatches();
        if ($week) {
            self::seedLabAndPending($week);
        }
    }

    /**
     * The real data only (safe on live): the 12 people and the week of 11/16 from the SCHEDULE tab.
     * Idempotent: people are matched by name, and the week is only made if it does not exist yet.
     * Touches no batches and no users. Returns the week (null if there is no active admin to own it).
     */
    public static function seedSheet(): ?\App\Models\ScheduleWeek
    {
        $i = 0;
        foreach (self::PEOPLE as [$name, $role, $hours, $off]) {
            ScheduleEmployee::firstOrCreate(['name' => $name], ['role' => $role, 'default_hours' => $hours, 'recurring_off' => $off, 'active' => true, 'sort_order' => ++$i]);
        }
        $svc = app(ScheduleService::class);
        $monday = CarbonImmutable::parse(self::WEEK);
        if ($w = $svc->find($monday)) {
            return $w;   // already there: leave whatever people changed
        }
        $by = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->orderBy('id')->first();
        if (! $by) {
            return null;
        }
        $week = $svc->startWeek($monday, $by, false);
        foreach (self::CELLS as $name => $days) {
            $e = ScheduleEmployee::where('name', $name)->firstOrFail();
            foreach ($days as $k => $text) {
                $text = trim($text);
                if ($text === '') {
                    continue;
                }
                $partial = self::PARTIAL[$name][$k] ?? null;
                $status = strtolower($text) === 'off' ? 'off' : ($partial ? 'partial' : 'working');
                $svc->cell($week, $e, $monday->addDays($k))->fill(['week_id' => $week->id, 'status' => $status, 'hours' => $partial,
                    'note' => $status === 'off' ? null : $text, 'from_recurring' => false])->save();
            }
        }

        return $week;
    }

    /** Fake batches TEST-001... across Incoming / In production / In lab, on real preview products. Once only. */
    public static function seedTestBatches(): void
    {
        if (! Preview::enabled()) {
            throw new \RuntimeException('Test batches are only for the preview site.');
        }
        if (\App\Models\Batch::where('batch_number', 'like', 'TEST-%')->exists()) {
            return;
        }
        $by = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->orderBy('id')->firstOrFail();
        $wf = app(\App\Services\ProductionWorkflow::class);
        $ready = \App\Models\Mmp::where('status', 'approved')->pluck('product_id')->unique()->values();
        $products = \App\Models\Product::where('active', true)->orderBy('id')->get();
        $i = 0;
        foreach (self::TEST_BATCHES as $num => [$status, $units]) {
            $pool = $status === 'incoming' ? $products : $products->whereIn('id', $ready)->values();
            if ($pool->isEmpty()) {
                continue;
            }
            $p = $pool[$i++ % $pool->count()];
            $b = $wf->createIncoming($p, $by, ['batch_number' => $num, 'planned_units' => $units,
                'planned_date' => $status === 'incoming' ? CarbonImmutable::parse(self::WEEK)->addDays(7 + $i % 4)->toDateString() : null]);
            if ($status !== 'incoming') {
                $b = $wf->startIncoming($b->fresh(), $units ?: 1000, null, $by);
                if ($status !== 'in_production') {
                    $b->update(['log_status' => $status]);
                    \App\Services\Audit::log('batch_log_status', $b, ['old' => 'in_production', 'new' => $status, 'via' => 'preview_test_data']);
                }
            }
        }
    }

    public static function seedLabAndPending(\App\Models\ScheduleWeek $week): void
    {
        if (ScheduleNote::where('week_id', $week->id)->whereIn('section', [ScheduleNote::PENDING, ScheduleNote::GOING_TO_LAB])->exists()) {
            return;
        }
        $n = (int) ScheduleNote::where('week_id', $week->id)->max('sort_order');
        $by = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->orderBy('id')->firstOrFail();
        foreach (self::GOING_TO_LAB as $num => $done) {
            if ($b = \App\Models\Batch::where('batch_number', $num)->first()) {
                $note = ScheduleNote::create(['week_id' => $week->id, 'section' => ScheduleNote::GOING_TO_LAB, 'batch_id' => $b->id, 'text' => '', 'sort_order' => ++$n]);
                if ($done) {
                    \App\Http\Controllers\ScheduleController::setGoneToLab($note, true, $by);
                }
            }
        }
        foreach (self::PENDING as [$num, $text]) {
            $b = \App\Models\Batch::where('batch_number', $num)->first();
            ScheduleNote::create(['week_id' => $week->id, 'section' => ScheduleNote::PENDING, 'batch_id' => $b?->id, 'text' => $text, 'sort_order' => ++$n]);
        }
    }
}
