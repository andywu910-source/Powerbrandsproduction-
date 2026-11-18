<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Product;
use App\Models\ScheduleCell;
use App\Models\ScheduleEmployee;
use App\Models\ScheduleJob;
use App\Models\ScheduleNote;
use App\Models\ScheduleRoster;
use App\Models\ScheduleWeek;
use App\Models\Setting;
use App\Services\Audit;
use App\Services\ScheduleService;
use App\Support\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Weekly Schedule (Andy, Nov 17). Editors: Schedule::canEdit(); everyone else signed in can look. */
class ScheduleController extends Controller
{
    public function __construct(private ScheduleService $svc) {}

    private function editOnly(Request $r): void
    {
        abort_unless(Schedule::canEdit($r->user()), 403, 'Only schedule editors can change the schedule.');
    }

    public function index(Request $r)
    {
        $monday = Schedule::monday($r->input('week'));
        $week = $this->svc->find($monday);

        return view('schedule.index', [
            'monday' => $monday, 'week' => $week, 'grid' => $week ? $this->svc->grid($week) : null,
            'days' => array_map(fn ($i) => $monday->addDays($i), range(0, 4)),
            'canEdit' => Schedule::canEdit($r->user()), 'prev' => $this->svc->previous($monday),
            'sections' => Schedule::sections(), 'showLabor' => Schedule::showLabor(),
            'notes' => $week ? $week->notes()->with('batch.product.brand')->get()->groupBy('section') : collect(),
            'incomingBatches' => \App\Models\Batch::with('product.brand')->where('log_status', 'incoming')->where('status', '!=', 'void')->orderBy('batch_number')->get(),
            'inLab' => \App\Models\Batch::with('product.brand')->where('log_status', 'in_lab')->where('status', '!=', 'void')->orderBy('batch_number')->get(),
            'openBatches' => self::openBatches(),
            'addable' => $week ? ScheduleEmployee::where('active', true)->whereNotIn('id', $week->roster()->pluck('employee_id'))->orderBy('name')->get() : collect(),
        ]);
    }

    public function today(Request $r)
    {
        $day = $r->input('day') ? CarbonImmutable::parse($r->input('day'), config('pbp.site_timezone'))->startOfDay() : Schedule::today();
        $week = $this->svc->find(Schedule::monday($day->toDateString()));
        $rows = [];
        if ($week && $day->isWeekday()) {
            $grid = $this->svc->grid($week);
            foreach ($grid['rows'] as $row) {
                $rows[] = $row + ['cell' => $row['cells']->get($day->toDateString())];
            }
        }

        return view('schedule.today', ['day' => $day, 'week' => $week, 'rows' => $rows, 'canEdit' => Schedule::canEdit($r->user()),
            'notes' => $week ? $week->notes()->with('batch')->get()->groupBy('section') : collect(), 'sections' => Schedule::sections()]);
    }

    public function copyForm(Request $r)
    {
        $this->editOnly($r);
        $monday = Schedule::monday($r->input('week'));
        if ($w = $this->svc->find($monday)) {
            return redirect()->route('schedule.index', ['week' => $monday->toDateString()])->with('status', $w->label().' is already set up.');
        }

        return view('schedule.copy', ['monday' => $monday, 'plan' => $this->svc->copyPlan($monday)]);
    }

    public function start(Request $r)
    {
        $this->editOnly($r);
        $d = $r->validate(['week' => ['required', 'date'], 'copy' => ['nullable', 'boolean']]);
        $monday = Schedule::monday($d['week']);
        $week = $this->svc->startWeek($monday, $r->user(), (bool) ($d['copy'] ?? false));
        $n = $week->roster()->count();
        $offs = ScheduleCell::where('week_id', $week->id)->where('from_recurring', true)->count();

        return redirect()->route('schedule.index', ['week' => $monday->toDateString()])
            ->with('status', $week->label().' set up: '.$n.' people'.($offs ? ", $offs recurring day".($offs === 1 ? '' : 's').' off filled in' : '').'.'.($week->copied_from_id ? ' Jobs are not copied.' : ''));
    }

    public function cell(Request $r, ScheduleWeek $week, ScheduleEmployee $employee, string $day)
    {
        $d = CarbonImmutable::parse($day)->startOfDay();
        $cell = $this->svc->cell($week, $employee, $d);
        $roster = ScheduleRoster::where('week_id', $week->id)->where('employee_id', $employee->id)->firstOrFail();
        $products = Product::with('brand')->where('active', true)->orderBy('name')->get();

        return view('schedule.cell', [
            'week' => $week, 'employee' => $employee, 'day' => $d, 'cell' => $cell, 'roster' => $roster,
            'jobs' => $cell->exists ? $cell->jobs()->with(['product.brand', 'batch'])->get() : collect(),
            'canEdit' => Schedule::canEdit($r->user()), 'steps' => Schedule::steps(),
            'products' => $products, 'selected' => null, 'openBatches' => self::openBatches(),
            'readyIds' => \App\Models\Mmp::where('status', 'approved')->pluck('product_id')->unique()->all(),
        ]);
    }

    public function saveCell(Request $r, ScheduleWeek $week, ScheduleEmployee $employee, string $day)
    {
        $this->editOnly($r);
        $v = $r->validate([
            'status' => ['nullable', 'in:working,off,partial'],
            'hours' => ['nullable', 'required_if:status,partial', 'numeric', 'min:0.25', 'max:16'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['hours.required_if' => 'Type the hours for a partial day.']);
        $cell = $this->svc->cell($week, $employee, CarbonImmutable::parse($day)->startOfDay());
        $cell->fill(['week_id' => $week->id, 'status' => $v['status'] ?? null, 'hours' => ($v['status'] ?? null) === 'partial' ? $v['hours'] : null,
            'note' => trim((string) ($v['note'] ?? '')) ?: null, 'from_recurring' => false])->save();

        return $this->back($r, $week, $employee, $cell, 'Saved.');
    }

    /** Batches that are not Finished (and not void): what a plan or a Pending note can point at. */
    public static function openBatches()
    {
        return \App\Models\Batch::with('product.brand')->where('status', '!=', 'void')
            ->where(fn ($q) => $q->whereNull('log_status')->orWhere('log_status', '!=', 'finished'))->orderBy('batch_number')->get();
    }

    /** "BATCH# — Product" (what the search box shows). */
    public static function batchOption(\App\Models\Batch $b): string
    {
        return $b->batch_number.' — '.trim(($b->product?->brand?->name ? $b->product->brand->name.' ' : '').($b->product?->name ?? ''));
    }

    /** Resolve the one-line search text to an open batch (exact option, batch number, or a product with one open batch). */
    public static function resolveOpenBatch(string $q): ?\App\Models\Batch
    {
        $q = trim($q);
        if ($q === '') {
            return null;
        }
        $open = self::openBatches();
        $num = mb_strtolower(trim(explode(' — ', $q)[0]));
        $hit = $open->first(fn ($b) => mb_strtolower($b->batch_number) === $num);
        if ($hit) {
            return $hit;
        }
        $byProduct = $open->filter(fn ($b) => str_contains(mb_strtolower(self::batchOption($b)), mb_strtolower($q)));

        return $byProduct->count() === 1 ? $byProduct->first() : null;
    }

    public function addJob(Request $r, ScheduleWeek $week, ScheduleEmployee $employee, string $day)
    {
        $this->editOnly($r);
        $v = $r->validate([
            'batch' => ['required', 'string', 'max:200'],
            'process_step' => ['nullable', 'string', 'max:60'],
            'job_note' => ['nullable', 'string', 'max:255'],
        ], ['batch.required' => 'Search a batch number or product and pick one from the list.']);
        $batch = self::resolveOpenBatch($v['batch']);
        if (! $batch) {
            return back()->withErrors(['batch' => 'Pick a batch from the list (batches that are not Finished). If the product has more than one open batch, type its batch number.'])->withInput();
        }
        $cell = $this->svc->cell($week, $employee, CarbonImmutable::parse($day)->startOfDay());
        if (! $cell->exists || $cell->status === null) {
            $cell->fill(['week_id' => $week->id, 'status' => $cell->status ?? 'working'])->save();   // a job means the person is working that day
        }
        ScheduleJob::create(['cell_id' => $cell->id, 'product_id' => $batch->product_id, 'batch_id' => $batch->id,
            'process_step' => trim((string) ($v['process_step'] ?? '')) ?: null, 'note' => trim((string) ($v['job_note'] ?? '')) ?: null,
            'sort_order' => (int) ScheduleJob::where('cell_id', $cell->id)->max('sort_order') + 1]);

        return $this->back($r, $week, $employee, $cell, 'Job added.');
    }

    public function saveJob(Request $r, ScheduleJob $job)
    {
        $this->editOnly($r);
        $v = $r->validate([
            'process_step' => ['nullable', 'string', 'max:60'],
            'job_note' => ['nullable', 'string', 'max:255'],
            'batch_number' => ['nullable', 'string', 'max:60'],
        ]);
        $job->update(['process_step' => trim((string) ($v['process_step'] ?? '')) ?: null,
            'note' => trim((string) ($v['job_note'] ?? '')) ?: null]);
        if ($r->has('batch_number') && ($why = $this->svc->linkByNumber($job, (string) $v['batch_number']))) {
            return back()->withErrors(['batch_number' => $why])->withInput();
        }
        $c = $job->cell;

        return $this->back($r, $c->week, $c->employee, $c, 'Job saved.');
    }

    public function removeJob(Request $r, ScheduleJob $job)
    {
        $this->editOnly($r);
        $job->update(['removed_at' => now()]);   // kept in the database and audit log
        $c = $job->cell;

        return $this->back($r, $c->week, $c->employee, $c, 'Job removed from the schedule.');
    }

    public function addPerson(Request $r, ScheduleWeek $week)
    {
        $this->editOnly($r);
        $v = $r->validate(['employee_id' => ['required', 'exists:schedule_employees,id']]);
        $this->svc->addToRoster($week, ScheduleEmployee::findOrFail($v['employee_id']));

        return redirect()->route('schedule.index', ['week' => $week->week_start->toDateString()])->with('status', 'Added to this week.');
    }

    public function saveRoster(Request $r, ScheduleRoster $roster)
    {
        $this->editOnly($r);
        if ($r->boolean('remove')) {
            $roster->update(['removed_at' => now()]);
            $msg = $roster->employee->name.' taken off this week (their days stay saved).';
        } else {
            $v = $r->validate(['daily_hours' => ['required', 'numeric', 'min:0', 'max:16']]);
            $roster->update(['daily_hours' => $v['daily_hours']]);
            $msg = 'Day length saved.';
        }

        return redirect()->route('schedule.index', ['week' => $roster->week->week_start->toDateString()])->with('status', $msg);
    }

    public function addNote(Request $r, ScheduleWeek $week)
    {
        $this->editOnly($r);
        $v = $r->validate(['section' => ['required', 'in:'.ScheduleNote::GOING_TO_LAB.','.ScheduleNote::PENDING],
            'batch' => ['nullable', 'string', 'max:200'], 'text' => ['nullable', 'string', 'max:255']]);
        $batch = null;
        if ($v['section'] === ScheduleNote::GOING_TO_LAB) {
            $num = mb_strtolower(trim(explode(' — ', (string) ($v['batch'] ?? ''))[0]));
            $batch = \App\Models\Batch::where('log_status', 'incoming')->where('status', '!=', 'void')->get()->first(fn ($b) => mb_strtolower($b->batch_number) === $num);
            if (! $batch) {
                return back()->withErrors(['lab_batch' => 'Pick an Incoming job from the list.'])->withInput();
            }
            if (ScheduleNote::where('week_id', $week->id)->where('section', $v['section'])->where('batch_id', $batch->id)->whereNull('removed_at')->exists()) {
                return redirect()->to(route('schedule.index', ['week' => $week->week_start->toDateString()]).'#lab');
            }
        } else {
            if (trim((string) ($v['batch'] ?? '')) !== '') {
                $batch = self::resolveOpenBatch((string) $v['batch']);
                if (! $batch) {
                    return back()->withErrors(['pending_batch' => 'Pick a batch from the list (batches that are not Finished), or leave it empty.'])->withInput();
                }
            }
            if (trim((string) ($v['text'] ?? '')) === '') {
                return back()->withErrors(['pending_text' => 'Type the note, e.g. waiting for apollo labels.'])->withInput();
            }
        }
        ScheduleNote::create(['week_id' => $week->id, 'section' => $v['section'], 'batch_id' => $batch?->id, 'text' => trim((string) ($v['text'] ?? '')),
            'sort_order' => (int) ScheduleNote::where('week_id', $week->id)->max('sort_order') + 1]);

        return redirect()->to(route('schedule.index', ['week' => $week->week_start->toDateString()]).'#lab');
    }

    public function saveNote(Request $r, ScheduleNote $note)
    {
        $this->editOnly($r);
        if ($r->boolean('remove')) {
            $note->update(['removed_at' => now()]);   // kept in the database and audit log
        } elseif ($r->has('lab_done') && $note->section === ScheduleNote::GOING_TO_LAB) {
            self::setGoneToLab($note, $r->boolean('lab_done'), $r->user());
        } else {
            $v = $r->validate(['text' => ['required', 'string', 'max:255']]);
            $note->update(['text' => trim($v['text'])]);
        }

        return redirect()->to(route('schedule.index', ['week' => $note->week->week_start->toDateString()]).'#lab');
    }

    /** Going to lab checkbox: checked = the batch is officially at the lab (Incoming -> In lab); unchecked puts it back if it is still In lab. */
    public static function setGoneToLab(ScheduleNote $note, bool $done, \App\Models\User $by): void
    {
        $note->update(['done' => $done]);
        $b = $note->batch;
        if (! $b) {
            return;
        }
        $old = $b->logStatus();
        $new = $done ? ($old === 'incoming' ? 'in_lab' : null) : ($old === 'in_lab' && $b->isIncoming() ? 'incoming' : null);
        if ($new) {
            $b->update(['log_status' => $new]);
            \App\Services\Audit::log('batch_log_status', $b, ['old' => $old, 'new' => $new, 'via' => 'schedule_going_to_lab']);
        }
    }

    public function people(Request $r)
    {
        return view('schedule.people', [
            'people' => ScheduleEmployee::orderByDesc('active')->orderBy('sort_order')->orderBy('name')->get(),
            'canEdit' => Schedule::canEdit($r->user()), 'isAdmin' => $r->user()->isAdmin(),
            'editorSpec' => Schedule::editorSpec(), 'editorsNow' => Schedule::editorsNow(),
            'sections' => Schedule::sections(), 'steps' => Schedule::steps(), 'showLabor' => Schedule::showLabor(),
            'users' => \App\Models\User::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function savePerson(Request $r, ?ScheduleEmployee $employee = null)
    {
        $this->editOnly($r);
        $v = $r->validate([
            'name' => ['required', 'string', 'max:80'], 'role' => ['nullable', 'string', 'max:80'],
            'default_hours' => ['required', 'numeric', 'min:0', 'max:16'], 'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'recurring_off' => ['nullable', 'array'], 'recurring_off.*' => ['integer', 'between:1,5'],
            'active' => ['nullable', 'boolean'], 'user_id' => ['nullable', 'exists:users,id'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
        $data = ['name' => trim($v['name']), 'role' => trim((string) ($v['role'] ?? '')) ?: null, 'default_hours' => $v['default_hours'],
            'recurring_off' => array_values(array_map('intval', $v['recurring_off'] ?? [])), 'active' => $r->boolean('active'),
            'user_id' => $v['user_id'] ?? null, 'sort_order' => (int) ($v['sort_order'] ?? 0)];
        if (Schedule::showLabor()) {
            $data['hourly_rate'] = $v['hourly_rate'] ?? null;
        }
        $employee && $employee->exists ? $employee->update($data) : ($employee = ScheduleEmployee::create($data));

        return redirect()->route('schedule.people')->with('status', $employee->name.' saved. Changes apply to weeks set up from now on; this week\'s day length is set on the week.');
    }

    public function saveSetup(Request $r)
    {
        $this->editOnly($r);
        $v = $r->validate(['sections' => ['nullable', 'string', 'max:2000'], 'steps' => ['nullable', 'string', 'max:2000'], 'editors' => ['nullable', 'string', 'max:1000']]);
        $lines = fn ($s) => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $s)), fn ($x) => $x !== ''));
        if ($r->has('sections')) {
            Setting::put('schedule_sections', $lines($v['sections']), $r->user());
        }
        if ($r->has('steps')) {
            Setting::put('schedule_steps', $lines($v['steps']), $r->user());
        }
        if ($r->has('editors')) {
            abort_unless($r->user()->isAdmin(), 403, 'Only admins can change who edits the schedule.');
            $spec = implode(', ', $lines(str_replace(',', "\n", (string) $v['editors'])));
            Setting::put('schedule_editors', $spec, $r->user());
            if (! Schedule::canEdit($r->user())) {   // never lock yourself out
                Setting::put('schedule_editors', 'role:admin, '.$spec, $r->user());
            }
        }

        return redirect()->route('schedule.people')->with('status', 'Schedule setup saved.');
    }

    private function back(Request $r, ScheduleWeek $week, ScheduleEmployee $e, ScheduleCell $cell, string $msg)
    {
        if ($r->input('return') === 'week') {
            return redirect()->route('schedule.index', ['week' => $week->week_start->toDateString()])->with('status', $msg);
        }

        return redirect()->route('schedule.cell', [$week, $e, $cell->day->toDateString()])->with('status', $msg);
    }
}
