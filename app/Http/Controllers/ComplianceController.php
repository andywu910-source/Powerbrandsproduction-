<?php

namespace App\Http\Controllers;

use App\Models\ComplianceEntry;
use App\Models\ComplianceItem;
use App\Support\Compliance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Compliance Checklists: weekly (Mon-Sun) and monthly, with history. Internal, not official OCM documents. */
class ComplianceController extends Controller
{
    private function freq(string $f): string
    {
        abort_unless(isset(ComplianceItem::FREQUENCIES[$f]), 404);

        return $f;
    }

    public function home()
    {
        return redirect()->route('compliance.period', 'weekly');
    }

    public function period(Request $r, string $freq)
    {
        $freq = $this->freq($freq);
        $start = Compliance::periodStart($freq, $r->input('period'));
        $items = Compliance::items($freq, $start);
        $entries = ComplianceEntry::with('assignee', 'completedBy')->whereIn('item_id', $items->pluck('id'))
            ->whereDate('period_start', $start->toDateString())->get()->keyBy('item_id');

        return view('compliance.period', [
            'freq' => $freq, 'start' => $start, 'end' => Compliance::periodEnd($freq, $start), 'items' => $items, 'entries' => $entries,
            'progress' => Compliance::progress($freq, $start), 'canEdit' => Compliance::canEdit($r->user(), $freq, $start),
            'isCurrent' => Compliance::isCurrent($freq, $start), 'pickable' => Compliance::pickable(),
            'prev' => Compliance::step($freq, $start, -1), 'next' => Compliance::step($freq, $start, 1),
        ]);
    }

    public function save(Request $r, string $freq, string $period, ComplianceItem $item)
    {
        $freq = $this->freq($freq);
        abort_unless($item->frequency === $freq, 404);
        $start = Compliance::periodStart($freq, $period);
        abort_unless($start->toDateString() === CarbonImmutable::parse($period)->toDateString(), 404);
        abort_unless(Compliance::canEdit($r->user(), $freq, $start), 403, Compliance::canCheck($r->user())
            ? 'Past periods are read-only. Only admins can change them.' : 'Read-only accounts cannot change checklists.');
        $ids = Compliance::pickable()->pluck('id')->all();
        $msg = 'That person cannot be picked for compliance checklists.';
        $d = $r->validate([
            'done' => ['nullable', 'boolean'],
            'assignee_user_id' => ['nullable', 'integer', Rule::in($ids)],
            'completed_by_user_id' => ['nullable', 'integer', Rule::in($ids)],
            'completed_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['assignee_user_id.in' => $msg, 'completed_by_user_id.in' => $msg]);

        $e = ComplianceEntry::where('item_id', $item->id)->whereDate('period_start', $start->toDateString())->first()
            ?? new ComplianceEntry(['item_id' => $item->id, 'period_start' => $start->toDateString()]);
        $done = $r->has('done') ? (bool) ($d['done'] ?? false) : (bool) $e->done;
        $fill = ['done' => $done, 'updated_by_user_id' => $r->user()->id];
        if ($r->has('assignee_user_id')) {
            $fill['assignee_user_id'] = $d['assignee_user_id'] ?? null;
        }
        if ($r->has('note')) {
            $fill['note'] = trim((string) ($d['note'] ?? '')) ?: null;
        }
        if ($done) {
            $by = $d['completed_by_user_id'] ?? ($r->has('completed_by_user_id') ? null : $e->completed_by_user_id);
            if (! $by) {
                if (Compliance::excluded($r->user())) {
                    return back()->withErrors(['completed_by_user_id' => 'Pick who completed it.'])->withInput();
                }
                $by = $r->user()->id;   // default: the person checking it
            }
            $fill['completed_by_user_id'] = $by;
            $fill['completed_on'] = $d['completed_on'] ?? ($e->completed_on?->toDateString() ?? Compliance::today()->toDateString());
        } else {
            $fill['completed_by_user_id'] = null;
            $fill['completed_on'] = null;
        }
        $e->fill($fill)->save();

        return redirect()->to(route('compliance.period', [$freq, 'period' => $start->toDateString()]).'#item-'.$item->id)
            ->with('status', $done ? "Checked: {$item->title}." : "Saved: {$item->title}.");
    }

    public function history(Request $r)
    {
        $rows = [];
        foreach (array_keys(ComplianceItem::FREQUENCIES) as $f) {
            $cur = Compliance::periodStart($f);
            $starts = ComplianceEntry::whereIn('item_id', ComplianceItem::where('frequency', $f)->pluck('id'))->pluck('period_start')
                ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->push($cur->toDateString());
            for ($i = 1; $i <= ['weekly' => 8, 'monthly' => 6, 'quarterly' => 4, 'annual' => 2][$f]; $i++) {
                $starts->push(Compliance::step($f, $cur, -$i)->toDateString());
            }
            foreach ($starts->unique()->sortDesc() as $s) {
                $st = CarbonImmutable::parse($s, Compliance::tz());
                $rows[$f][] = ['start' => $st, 'label' => Compliance::label($f, $st), 'progress' => Compliance::progress($f, $st), 'current' => Compliance::isCurrent($f, $st)];
            }
        }

        return view('compliance.history', ['rows' => $rows]);
    }

    public function setup(Request $r)
    {
        return view('compliance.setup', ['items' => ComplianceItem::orderBy('frequency', 'desc')->orderBy('sort_order')->orderBy('id')->get()->groupBy('frequency'),
            'isAdmin' => $r->user()->isAdmin()]);
    }

    public function addItem(Request $r)
    {
        abort_unless($r->user()->isAdmin(), 403);
        $d = $r->validate(['frequency' => ['required', Rule::in(array_keys(ComplianceItem::FREQUENCIES))], 'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'], 'standing' => ['nullable', 'boolean']]);
        $d['standing'] = $d['frequency'] === 'annual' && ! empty($d['standing']);
        ComplianceItem::create($d + ['sort_order' => (int) ComplianceItem::where('frequency', $d['frequency'])->max('sort_order') + 1, 'hidden' => false]);

        return back()->with('status', 'Item added.');
    }

    public function saveItem(Request $r, ComplianceItem $item)
    {
        abort_unless($r->user()->isAdmin(), 403);
        if ($r->filled('move')) {
            $sib = ComplianceItem::where('frequency', $item->frequency)->orderBy('sort_order')->orderBy('id')->get()->values();
            $i = $sib->search(fn ($x) => $x->id === $item->id);
            $j = $r->input('move') === 'up' ? $i - 1 : $i + 1;
            if ($j >= 0 && $j < $sib->count()) {
                $order = $sib->pluck('id')->all();
                [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
                foreach ($order as $n => $id) {
                    $m = $sib->firstWhere('id', $id);
                    if ($m->sort_order !== $n + 1) {
                        $m->update(['sort_order' => $n + 1]);
                    }
                }
            }

            return back();
        }
        $d = $r->validate(['title' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'], 'hidden' => ['nullable', 'boolean']]);
        $item->update(['title' => $d['title'], 'description' => $d['description'] ?? null, 'hidden' => (bool) ($d['hidden'] ?? false)]);

        return back()->with('status', 'Item saved.');
    }
}
