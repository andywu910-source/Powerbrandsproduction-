<?php

namespace App\Http\Controllers;

use App\Models\ChecklistInstance;
use App\Models\ChecklistTemplate;
use App\Models\Deviation;
use App\Models\Person;
use App\Models\User;
use App\Services\ChecklistService;
use App\Services\DeviationService;
use Illuminate\Http\Request;

class ChecklistController extends Controller
{
    public function __construct(private ChecklistService $svc) {}

    public function index(Request $r)
    {
        $this->svc->generate();   // make sure today's are there even if cron is late
        $open = ChecklistInstance::with('template', 'assignee', 'batch')->whereIn('status', ['due', 'in_progress', 'overdue', 'awaiting_verification'])
            ->orderByRaw('overdue_at is null')->orderBy('due_at')->get();
        $done = ChecklistInstance::with('template')->where('status', 'completed')->orderByDesc('completed_at')->limit(30)->get();

        return view('checklists.index', ['open' => $open, 'done' => $done,
            'onDemand' => ChecklistTemplate::where('active', true)->where('frequency', 'on_demand')->where('code', '!=', 'changeover_clean')->orderBy('name')->get(),
            'templates' => ChecklistTemplate::with('currentVersion')->orderBy('category')->orderBy('name')->get()]);
    }

    public function start(Request $r, ChecklistTemplate $template)
    {
        $i = $this->svc->createOnDemand($template, (string) $r->input('scope', ''));

        return redirect()->route('checklists.show', $i);
    }

    public function show(ChecklistInstance $checklist)
    {
        $checklist->load('template', 'version', 'items.templateItem', 'items.answeredBy', 'items.deviation', 'items.revisions.correctedBy', 'signatures.user', 'batch', 'revisions', 'performer');

        return view('checklists.show', ['i' => $checklist, 'signers' => User::signers(), 'people' => Person::where('active', true)->orderBy('full_name')->get(),
            'problems' => $checklist->isOpen() ? $this->svc->completionProblems($checklist) : [],
            'deviations' => Deviation::whereNull('voided_at')->where('status', '!=', 'closed')->orderByDesc('id')->limit(50)->get()]);
    }

    public function answers(Request $r, ChecklistInstance $checklist)
    {
        $this->svc->saveAnswers($checklist, (array) $r->input('items', []), $r->user());

        return $this->ok('Answers saved. Sign below when finished.');
    }

    public function complete(Request $r, ChecklistInstance $checklist)
    {
        if ($r->has('items')) {
            $this->svc->saveAnswers($checklist, (array) $r->input('items', []), $r->user());
        }
        $this->svc->complete($checklist, $this->signer($r), $r->user(), $r->input('late_entry_reason'), $r->integer('performed_by_person_id') ?: null);
        $checklist->refresh();

        return $checklist->batch_id && $checklist->status === 'completed'
            ? redirect()->route('production.show', $checklist->batch)->with('status', 'Changeover cleaning signed.')
            : $this->ok($checklist->status === 'completed' ? 'Checklist completed and signed.' : 'Signed. Now a second person must verify it.');
    }

    public function verify(Request $r, ChecklistInstance $checklist)
    {
        $this->svc->verify($checklist, $this->signer($r), $r->user());
        $checklist->refresh();

        return $checklist->batch_id
            ? redirect()->route('production.show', $checklist->batch)->with('status', 'Changeover cleaning verified.')
            : $this->ok('Checklist verified.');
    }

    public function deviation(Request $r, ChecklistInstance $checklist, int $item)
    {
        $it = $checklist->items()->with('templateItem')->findOrFail($item);
        $d = app(DeviationService::class)->open($checklist->batch, $it, 'Checklist item failed: '.$checklist->template->name.' #'.$it->templateItem->item_no,
            (string) ($r->input('description') ?: $it->templateItem->prompt), false, false, $this->signer($r), $r->user());
        $it->update(['deviation_id' => $d->id]);

        return $this->ok("Deviation {$d->public_id} opened and linked.");
    }
}
