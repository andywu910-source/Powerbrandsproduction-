<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Deviation;
use App\Models\User;
use App\Services\DeviationService;
use Illuminate\Http\Request;

class DeviationController extends Controller
{
    public function __construct(private DeviationService $svc) {}

    public function index(Request $r)
    {
        $q = Deviation::with('batch')->orderByDesc('id');
        if ($r->input('status', 'open') === 'open') {
            $q->whereIn('status', ['open', 'approved_to_continue']);
        }

        return view('deviations.index', ['devs' => $q->paginate(30)->withQueryString()]);
    }

    public function show(Deviation $deviation)
    {
        $deviation->load('batch', 'lot', 'signatures.user', 'revisions.correctedBy');

        return view('deviations.show', ['d' => $deviation, 'signers' => User::signers(), 'qcReviewers' => User::signers()->filter->isQcReviewer()]);
    }

    public function store(Request $r)
    {
        $d = $r->validate(['batch_id' => ['nullable', 'exists:batches,id'], 'title' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:5000']]);
        $dev = $this->svc->open(! empty($d['batch_id']) ? Batch::find($d['batch_id']) : null, null, $d['title'], $d['description'], $r->boolean('active_ingredient'), $r->boolean('requires_qc_approval'), $this->signer($r), $r->user());

        return redirect()->route('deviations.show', $dev)->with('status', "Deviation {$dev->public_id} opened.");
    }

    public function approve(Request $r, Deviation $deviation)
    {
        $this->svc->approveContinue($deviation, $this->signer($r), $r->user());

        return $this->ok('QC approved continuing.');
    }

    public function close(Request $r, Deviation $deviation)
    {
        $this->svc->close($deviation, $r->only(['root_cause', 'corrective_action', 'disposition', 'impact']), $this->signer($r), $r->user());

        return $this->ok('Deviation closed.');
    }
}
