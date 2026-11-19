<?php

namespace App\Http\Controllers;

use App\Models\ComplianceEntry;
use App\Models\ComplianceItem;
use App\Models\User;
use App\Support\Compliance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $entries = ComplianceEntry::with('assignee', 'completedBy', 'signatures.user', 'revisions')->whereIn('item_id', $items->pluck('id'))
            ->whereDate('period_start', $start->toDateString())->get()->keyBy('item_id');

        return view('compliance.period', [
            'freq' => $freq, 'start' => $start, 'end' => Compliance::periodEnd($freq, $start), 'items' => $items, 'entries' => $entries,
            'progress' => Compliance::progress($freq, $start), 'canEdit' => Compliance::canEdit($r->user(), $freq, $start),
            'isCurrent' => Compliance::isCurrent($freq, $start), 'pickable' => Compliance::pickable(),
            'prev' => Compliance::step($freq, $start, -1), 'next' => Compliance::step($freq, $start, 1),
        ]);
    }

    public function save(Request $r, string $freq, string $period, ComplianceItem $item, \App\Services\SignatureService $sigs)
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
        $wasDone = (bool) $e->done;
        $signed = $e->exists ? $e->validSignature() : null;   // a live sign-off: the check, who and when change only through "Revise"
        if ($signed && $r->boolean('revise')) {
            return $this->revise($r, $e, $signed, $item, $freq, $start);
        }
        $done = $r->has('done') ? (bool) ($d['done'] ?? false) : $wasDone;
        if ($signed && ! $done) {
            return back()->withErrors(['done' => 'This item is signed off by '.$signed->signerName().'. To change it, use "Revise my check-in" on the item (the original sign-off stays on record). An admin can also void a sign-off, with a reason.']);
        }
        $fill = ['done' => $done, 'updated_by_user_id' => $r->user()->id];
        if ($r->has('assignee_user_id')) {
            $fill['assignee_user_id'] = $d['assignee_user_id'] ?? null;
        }
        if ($r->has('note')) {
            $fill['note'] = trim((string) ($d['note'] ?? '')) ?: null;
        }
        if ($signed) {
            // Signed off: here only the assignee and note can change (kept in the item's history). Who completed it,
            // the date and the check itself change only through "Revise my check-in".
            $fill['done'] = true;
        } elseif ($done) {
            $by = $d['completed_by_user_id'] ?? ($r->has('completed_by_user_id') ? null : $e->completed_by_user_id);
            if ($r->user()->signsAsSelf()) {
                $by = $r->user()->id;   // staff sign off their own work (this includes Jack H, Andy Nov 19)
            }
            if (! $by) {
                if (! Compliance::canSignOff($r->user())) {
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
        // Checking an item (not done -> done) is a sign-off: who, name and time are recorded and can never be edited.
        $signNow = $done && ! $wasDone && ! $signed;
        $oldAssignee = $e->assignee?->name;
        $oldNote = $e->note;
        $afterRevisedUncheck = $signNow && $e->exists && ($e->revisions->whereIn('kind', ['revised', 'confirmed'])->last()?->kind === 'revised');
        DB::transaction(function () use ($e, $fill, $signNow, $signed, $r, $sigs, $oldAssignee, $oldNote, $afterRevisedUncheck) {
            $e->fill($fill)->save();
            if ($signNow) {
                $sig = $sigs->sign($e, 'completed', (int) $e->completed_by_user_id, $r->user());
                if ($afterRevisedUncheck) {   // un-checked through "Revise", now checked again: that is the confirmation
                    $this->addHistory($e, 'confirmed', $r->user(), [], 'Checked again after a revision', $sig->id);
                }
            } elseif ($signed) {
                $e->load('assignee');
                $ch = [];
                if ($oldAssignee !== $e->assignee?->name) {
                    $ch['Assignee'] = [$oldAssignee, $e->assignee?->name];
                }
                if ($oldNote !== $e->note) {
                    $ch['Note'] = [$oldNote, $e->note];
                }
                if ($ch) {
                    $this->addHistory($e, 'edited', $r->user(), $ch, null, $signed->id);
                }
            }
        });

        return redirect()->to(route('compliance.period', [$freq, 'period' => $start->toDateString()]).'#item-'.$item->id)
            ->with('status', $signNow ? "Signed off: {$item->title}." : ($done ? "Checked: {$item->title}." : "Saved: {$item->title}."));
    }

    /** Appends one row to the item's history (never edited or deleted). */
    private function addHistory(ComplianceEntry $e, string $kind, \App\Models\User $by, array $changes, ?string $reason, ?int $sigId): void
    {
        \App\Models\SignoffRevision::create([
            'signable_type' => $e->getMorphClass(), 'signable_id' => $e->getKey(), 'signature_id' => $sigId, 'kind' => $kind,
            'user_id' => $by->id, 'user_name' => $by->name, 'changes' => $changes ? json_encode($changes) : null, 'reason' => $reason,
        ]);
    }

    /**
     * Revise a signed check-in (Andy, Nov 19): the person who signed it (or an admin) may change the check, date, note
     * or assignee with a reason, then must re-confirm. The original sign-off, this revision (who, when, old -> new) and
     * the re-confirmation all stay on record. Nothing is deleted.
     */
    private function revise(Request $r, ComplianceEntry $e, \App\Models\Signature $signed, ComplianceItem $item, string $freq, CarbonImmutable $start)
    {
        $me = $r->user();
        abort_unless($me->isAdmin() || $signed->user_id === $me->id || $signed->recorded_by_user_id === $me->id, 403, 'Only the person who signed this (or an admin) can revise it.');
        $r->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], ['reason.required' => 'Say why you are revising it.', 'reason.min' => 'Say why you are revising it.']);
        $newDone = $r->boolean('done');
        $newDate = $r->filled('completed_on') ? CarbonImmutable::parse($r->input('completed_on'))->toDateString() : $e->completed_on?->toDateString();
        $newNote = $r->has('note') ? (trim((string) $r->input('note')) ?: null) : $e->note;
        $newAssignee = $r->has('assignee_user_id') ? ($r->integer('assignee_user_id') ?: null) : $e->assignee_user_id;
        if ($newAssignee && ! Compliance::pickable()->contains('id', $newAssignee)) {
            return back()->withErrors(['assignee_user_id' => 'That person cannot be picked for compliance checklists.']);
        }
        $names = fn ($id) => $id ? User::find($id)?->name : null;
        $ch = [];
        if ($newDone !== (bool) $e->done) {
            $ch['Done'] = [$e->done ? 'Done' : 'Not done', $newDone ? 'Done' : 'Not done'];
        }
        if ($newDone && $newDate !== $e->completed_on?->toDateString()) {
            $ch['Date'] = [$e->completed_on?->toDateString(), $newDate];
        }
        if ($newNote !== $e->note) {
            $ch['Note'] = [$e->note, $newNote];
        }
        if ($newAssignee !== $e->assignee_user_id) {
            $ch['Assignee'] = [$names($e->assignee_user_id), $names($newAssignee)];
        }
        if (! $ch) {
            return back()->withErrors(['revise' => 'Nothing was changed, so there is nothing to revise.']);
        }
        $reason = trim((string) $r->input('reason'));
        DB::transaction(function () use ($e, $signed, $me, $newDone, $newDate, $newNote, $newAssignee, $ch, $reason) {
            $e->fill([
                'done' => $newDone, 'note' => $newNote, 'assignee_user_id' => $newAssignee, 'updated_by_user_id' => $me->id,
                'completed_on' => $newDone ? $newDate : null, 'completed_by_user_id' => $newDone ? $e->completed_by_user_id : null,
            ])->save();
            if (! $newDone) {
                // Un-checked: the old sign-off is kept and marked "no longer valid"; checking again signs it afresh.
                $signed->forceFill(['invalidated_at' => now()])->save();
            }
            $this->addHistory($e, 'revised', $me, $ch, $reason, $signed->id);
            \App\Services\Audit::log('revise_signoff', $e, ['changes' => $ch, 'reason' => $reason, 'signature_id' => $signed->id]);
        });

        return redirect()->to(route('compliance.period', [$freq, 'period' => $start->toDateString()]).'#item-'.$item->id)
            ->with('status', $newDone ? "Revised: {$item->title}. Please confirm your revision." : "Un-checked: {$item->title}. The original sign-off stays on record.");
    }

    /** Re-confirm a revision: a new sign-off by the person who revised it (never anyone else). */
    public function confirm(Request $r, string $freq, string $period, ComplianceItem $item, \App\Services\SignatureService $sigs)
    {
        $freq = $this->freq($freq);
        abort_unless($item->frequency === $freq, 404);
        $start = Compliance::periodStart($freq, $period);
        abort_unless($start->toDateString() === CarbonImmutable::parse($period)->toDateString(), 404);
        abort_unless(Compliance::canEdit($r->user(), $freq, $start), 403, 'Past periods are read-only. Only admins can change them.');
        $e = ComplianceEntry::where('item_id', $item->id)->whereDate('period_start', $start->toDateString())->firstOrFail();
        $pending = $e->pendingRevision();
        abort_unless($pending, 404, 'There is no revision waiting to be confirmed.');
        abort_unless($pending->user_id === $r->user()->id, 403, 'Only '.$pending->user_name.', who made the revision, can confirm it.');
        DB::transaction(function () use ($e, $r, $sigs) {
            $sig = $sigs->sign($e, 'reconfirmed', $r->user()->id, $r->user());
            $this->addHistory($e, 'confirmed', $r->user(), [], null, $sig->id);
        });

        return redirect()->to(route('compliance.period', [$freq, 'period' => $start->toDateString()]).'#item-'.$item->id)
            ->with('status', "Re-confirmed: {$item->title}.");
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
