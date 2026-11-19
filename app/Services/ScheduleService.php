<?php

namespace App\Services;

use App\Exceptions\GateFailed;
use App\Models\Batch;
use App\Models\ScheduleCell;
use App\Models\ScheduleEmployee;
use App\Models\ScheduleJob;
use App\Models\ScheduleRoster;
use App\Models\ScheduleWeek;
use App\Models\User;
use App\Support\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ScheduleService
{
    public function find(CarbonImmutable $monday): ?ScheduleWeek
    {
        return ScheduleWeek::whereDate('week_start', $monday->toDateString())->first();
    }

    /** The latest set-up week before this Monday (what "Copy last week" copies). */
    public function previous(CarbonImmutable $monday): ?ScheduleWeek
    {
        return ScheduleWeek::whereDate('week_start', '<', $monday->toDateString())->orderByDesc('week_start')->first();
    }

    /** What "Copy last week" will bring over. */
    public function copyPlan(CarbonImmutable $monday): array
    {
        $prev = $this->previous($monday);
        $rows = [];
        if ($prev) {
            foreach ($prev->roster()->with('employee')->get() as $r) {
                $e = $r->employee;
                $rows[] = ['employee' => $e, 'active' => $e->active, 'hours' => (float) $e->default_hours, 'last_week_hours' => (float) $r->daily_hours,
                    'offs' => $e->active ? $e->recurringOffText() : ''];
            }
        }
        $new = ScheduleEmployee::where('active', true)->whereNotIn('id', array_map(fn ($r) => $r['employee']->id, $rows))->orderBy('sort_order')->orderBy('name')->get();

        return ['prev' => $prev, 'rows' => $rows, 'new' => $new];
    }

    /** Create the week: from the previous week's roster ($copy) or from every active person. Applies recurring days off. */
    public function startWeek(CarbonImmutable $monday, User $by, bool $copy): ScheduleWeek
    {
        return DB::transaction(function () use ($monday, $by, $copy) {
            if ($this->find($monday)) {
                throw GateFailed::because('This week is already set up.');
            }
            $prev = $copy ? $this->previous($monday) : null;
            if ($copy && ! $prev) {
                throw GateFailed::because('There is no earlier week to copy. Start this week from the people list instead.');
            }
            $week = ScheduleWeek::create(['week_start' => $monday->toDateString(), 'copied_from_id' => $prev?->id, 'created_by' => $by->id]);
            $people = $prev
                ? $prev->roster()->with('employee')->get()->map->employee->filter(fn ($e) => $e->active)
                : ScheduleEmployee::where('active', true)->orderBy('sort_order')->orderBy('name')->get();
            $i = 0;
            foreach ($people as $e) {
                $this->addToRoster($week, $e, ++$i);
            }
            Audit::log('schedule_week_start', $week, ['week' => $monday->toDateString(), 'copied_from' => $prev?->week_start?->toDateString(), 'people' => $people->count()]);

            return $week;
        });
    }

    public function addToRoster(ScheduleWeek $week, ScheduleEmployee $e, ?int $sort = null): ScheduleRoster
    {
        $r = ScheduleRoster::where('week_id', $week->id)->where('employee_id', $e->id)->first();
        if ($r) {
            if ($r->removed_at) {
                $r->update(['removed_at' => null]);
            }
        } else {
            $r = ScheduleRoster::create(['week_id' => $week->id, 'employee_id' => $e->id, 'daily_hours' => $e->default_hours,
                'sort_order' => $sort ?? ((int) ScheduleRoster::where('week_id', $week->id)->max('sort_order') + 1)]);
        }
        foreach ($week->days() as $day) {
            if ($e->offOn($day->dayOfWeekIso) && ! ScheduleCell::where('employee_id', $e->id)->whereDate('day', $day->toDateString())->exists()) {
                ScheduleCell::create(['week_id' => $week->id, 'employee_id' => $e->id, 'day' => $day->toDateString(), 'status' => 'off', 'from_recurring' => true,
                    'note' => 'Recurring day off']);
            }
        }

        return $r;
    }

    /** Grid rows: roster + cells by date + hours. */
    public function grid(ScheduleWeek $week): array
    {
        $cells = ScheduleCell::with(['jobs.product.brand', 'jobs.batch'])->where('week_id', $week->id)->get()
            ->groupBy('employee_id')->map(fn ($g) => $g->keyBy(fn ($c) => $c->day->toDateString()));
        $rows = [];
        $sum = 0.0;
        foreach ($week->roster()->with('employee')->get() as $r) {
            $byDay = $cells->get($r->employee_id, collect());
            $total = 0.0;
            foreach ($week->days() as $d) {
                $total += ScheduleCell::hoursFor($byDay->get($d->toDateString()), (float) $r->daily_hours);
            }
            $rate = $r->employee->hourly_rate ?? (float) config('pbp.schedule.default_rate', 20);
            $rows[] = ['roster' => $r, 'employee' => $r->employee, 'cells' => $byDay, 'hours' => $total, 'labor' => $total * $rate];
            $sum += $total;
        }

        return ['rows' => $rows, 'hours' => $sum];
    }

    /**
     * Bulk: set every given person x weekday of this week to working or off (row = one person's week, day = everyone
     * on one day). Notes and jobs stay. Each changed cell is audited as usual, plus one summary row for the action.
     *
     * @param  ScheduleEmployee[]  $people
     * @param  CarbonImmutable[]  $days
     */
    public function bulkSet(ScheduleWeek $week, array $people, array $days, string $status, \App\Models\User $by, string $scope): int
    {
        if (! in_array($status, ['working', 'off'], true)) {
            throw new \InvalidArgumentException('status must be working or off');
        }
        $changed = [];
        \Illuminate\Support\Facades\DB::transaction(function () use ($week, $people, $days, $status, &$changed) {
            foreach ($people as $e) {
                foreach ($days as $d) {
                    $cell = $this->cell($week, $e, $d);
                    if ($cell->exists && $cell->status === $status && $cell->hours === null) {
                        continue;   // already that (keeps a recurring day off marked as recurring)
                    }
                    $cell->fill(['week_id' => $week->id, 'status' => $status, 'hours' => null, 'from_recurring' => false]);
                    if (! $cell->exists || $cell->isDirty()) {
                        $cell->save();
                        $changed[] = $cell->id;
                    }
                }
            }
        });
        \App\Services\Audit::log('schedule_bulk', $week, ['scope' => $scope, 'status' => $status,
            'people' => array_map(fn ($e) => $e->name, $people), 'days' => array_map(fn ($d) => $d->toDateString(), $days),
            'changed' => count($changed), 'cell_ids' => $changed]);

        return count($changed);
    }

    public function cell(ScheduleWeek $week, ScheduleEmployee $e, CarbonImmutable $day): ScheduleCell
    {
        $this->assertDay($week, $day);

        return ScheduleCell::where('employee_id', $e->id)->whereDate('day', $day->toDateString())->first()
            ?? new ScheduleCell(['employee_id' => $e->id, 'day' => $day->toDateString(), 'week_id' => $week->id]);
    }

    public function assertDay(ScheduleWeek $week, CarbonImmutable $day): void
    {
        if (! in_array($day->toDateString(), array_map(fn ($d) => $d->toDateString(), $week->days()), true)) {
            throw GateFailed::because('That day is not Monday to Friday of this week.');
        }
    }

    /** "Start job" opened New Job and the batch was started: show it on the schedule cell. */
    public function linkStartedBatch(int $jobId, Batch $batch, User $by): void
    {
        $job = ScheduleJob::whereNull('removed_at')->whereNull('batch_id')->find($jobId);
        if (! $job || ($job->product_id && $job->product_id !== $batch->product_id)) {   // whoever may start the batch may link it
            return;
        }
        $job->update(['batch_id' => $batch->id]);
        Audit::log('schedule_job_batch', $job, ['batch_number' => $batch->batch_number, 'via' => 'start_job']);
    }

    /** Link an existing batch by its number (any case). */
    public function linkByNumber(ScheduleJob $job, string $number): ?string
    {
        $n = trim($number);
        if ($n === '') {
            if ($job->batch_id) {
                $job->update(['batch_id' => null]);
            }

            return null;
        }
        $b = Batch::whereRaw('LOWER(batch_number) = ?', [mb_strtolower($n)])->first();
        if (! $b) {
            return "No batch \"$n\" in the Batch Log.";
        }
        $job->update(['batch_id' => $b->id]);
        Audit::log('schedule_job_batch', $job, ['batch_number' => $b->batch_number, 'via' => 'typed']);

        return null;
    }
}
