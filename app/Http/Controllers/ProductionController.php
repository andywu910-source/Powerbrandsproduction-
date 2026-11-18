<?php

namespace App\Http\Controllers;

use App\Exceptions\GateFailed;
use App\Models\Attachment;
use App\Models\Batch;
use App\Models\BatchMaterialLine;
use App\Models\BatchMmpStep;
use App\Models\Equipment;
use App\Models\MaterialLot;
use App\Models\MmpLine;
use App\Models\MmpStep;
use App\Models\Person;
use App\Models\Product;
use App\Models\Room;
use App\Models\User;
use App\Services\BatchNumberGenerator;
use App\Services\Inventory\InventorySource;
use App\Services\ProductionWorkflow;
use App\Support\AttachmentStore;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    public function __construct(private ProductionWorkflow $wf) {}

    public function index(Request $r)
    {
        $batches = Batch::with('product.brand')->whereIn('status', ['in_process', 'in_qc'])->orderByDesc('id')->get();
        $products = Product::with('brand')->where('active', true)->orderBy('name')->get();
        $incoming = $r->filled('start') ? Batch::where('batch_number', (string) $r->input('start'))->where('status', 'planned')->first() : null;
        $selected = $incoming ? Product::with('brand')->find($incoming->product_id)
            : ($r->integer('product_id') ? $products->firstWhere('id', $r->integer('product_id')) : null);
        $scheduleJob = \App\Support\Modules::enabled('schedule') && $r->integer('schedule_job')
            ? \App\Models\ScheduleJob::with('cell.employee')->whereNull('removed_at')->whereNull('batch_id')->find($r->integer('schedule_job')) : null;

        return view('production.index', [
            'batches' => $batches,
            'products' => $products,
            'readyIds' => \App\Models\Mmp::where('status', 'approved')->pluck('product_id')->unique()->all(),
            'selected' => $selected,
            'selectedProblems' => $selected ? $this->wf->setupProblems($selected, $selected->currentMmp(), 1, null) : [],
            'suggest' => $selected ? BatchNumberGenerator::peek($selected) : null,   // optional "Suggest" button only
            'rooms' => Room::where('active', true)->orderBy('room_no')->get(),
            'incoming' => $incoming,
            'incomingJobs' => Batch::with('product.brand')->where('status', 'planned')->orderByRaw('planned_date IS NULL')->orderBy('planned_date')->orderBy('id')->get(),
            'scheduleJob' => $scheduleJob,
        ]);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'product_id' => ['required', 'exists:products,id'], 'target_units' => ['required', 'integer', 'min:1', 'max:'.\App\Support\OcmSampling::MAX_UNITS],
            'target_potency' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'room_id' => ['nullable', 'exists:rooms,id'], 'line' => ['nullable', 'string', 'max:100'],
            'schedule_job' => ['nullable', 'integer'],
            'batch_number' => ['required', 'string', function ($attr, $v, $fail) {
                if ($why = BatchNumberGenerator::typedProblem((string) $v)) {
                    $fail($why);
                }
            }],
        ], ['target_units.max' => \App\Support\OcmSampling::TOO_BIG, 'batch_number.required' => 'Type the batch number.']);
        $d['batch_number'] = trim($d['batch_number']);
        $batch = $this->wf->start(Product::findOrFail($d['product_id']), (int) $d['target_units'], isset($d['target_potency']) ? (float) $d['target_potency'] : null, $r->user(), $d);
        if (! empty($d['schedule_job']) && \App\Support\Modules::enabled('schedule')) {
            app(\App\Services\ScheduleService::class)->linkStartedBatch((int) $d['schedule_job'], $batch, $r->user());
        }

        return redirect()->route('production.show', $batch)->with('status', "Batch {$batch->batch_number} started. Complete step 1 to begin.");
    }

    /** Save a planned job as Incoming: product + typed batch number; planned units / date optional. No start checks. */
    public function storeIncoming(Request $r)
    {
        abort_if($r->user()->isViewer(), 403);
        $d = $r->validate([
            'product_id' => ['required', 'exists:products,id'],
            'planned_units' => ['nullable', 'integer', 'min:1', 'max:'.\App\Support\OcmSampling::MAX_UNITS],
            'planned_date' => ['nullable', 'date'],
            'schedule_job' => ['nullable', 'integer'],
            'batch_number' => ['required', 'string', function ($attr, $v, $fail) {
                if ($why = BatchNumberGenerator::typedProblem((string) $v)) {
                    $fail($why);
                }
            }],
        ], ['batch_number.required' => 'Type the batch number.', 'planned_units.max' => \App\Support\OcmSampling::TOO_BIG]);
        $b = $this->wf->createIncoming(Product::findOrFail($d['product_id']), $r->user(), ['batch_number' => trim($d['batch_number']),
            'planned_units' => isset($d['planned_units']) ? (int) $d['planned_units'] : null, 'planned_date' => $d['planned_date'] ?? null]);
        if (! empty($d['schedule_job']) && \App\Support\Modules::enabled('schedule')) {
            app(\App\Services\ScheduleService::class)->linkStartedBatch((int) $d['schedule_job'], $b, $r->user());
        }

        return redirect()->route('production.index')->with('status', "Incoming job {$b->batch_number} saved. Start it from Incoming jobs when it's time.");
    }

    /** "Start this job": Incoming -> In production, with every normal start check. */
    public function startIncoming(Request $r, Batch $batch)
    {
        $d = $r->validate([
            'target_units' => ['required', 'integer', 'min:1', 'max:'.\App\Support\OcmSampling::MAX_UNITS],
            'target_potency' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'room_id' => ['nullable', 'exists:rooms,id'], 'line' => ['nullable', 'string', 'max:100'],
        ], ['target_units.max' => \App\Support\OcmSampling::TOO_BIG]);
        $batch = $this->wf->startIncoming($batch, (int) $d['target_units'], isset($d['target_potency']) ? (float) $d['target_potency'] : null, $r->user(), $d);

        return redirect()->route('production.show', $batch)->with('status', "Batch {$batch->batch_number} started. Complete step 1 to begin.");
    }

    /** Live check while typing on Start a new batch: is this batch number free? (JSON) */
    public function checkNumber(Request $r)
    {
        $n = trim((string) $r->query('n', ''));
        $why = $n === '' ? null : BatchNumberGenerator::typedProblem($n);

        return response()->json(['number' => $n, 'ok' => $n !== '' && ! $why, 'message' => $why]);
    }

    public function show(Batch $batch)
    {
        if (in_array($batch->status, ['imported', 'planned'], true)) {
            return redirect()->route('batch-log.show', $batch);   // imported from the sheet: no production record
        }
        $batch->load(['product.brand', 'mmp.lines.ingredient', 'mmp.steps', 'mmp.pqp', 'steps.signatures.user', 'steps.checklist.template', 'room',
            'people.person', 'equipment.equipment', 'materialLines.lot', 'materialLines.ingredient', 'materialLines.signatures.user', 'materialLines.deviation',
            'mmpStepRecords.signatures.user', 'mmpStepRecords.deviation', 'mmpStepRecords.revisions', 'deviations', 'signatures.user', 'revisions', 'attachments']);
        $open = $batch->steps->firstWhere('status', 'open');
        $calc = $batch->currentCalculation();
        $inventory = app(InventorySource::class);
        $lotOptions = [];
        foreach ($batch->mmp->lines as $line) {
            $lotOptions[$line->id] = $inventory->listLots($line->ingredient, $batch->id)->map(fn ($l) => ['lot' => $l, 'available' => $inventory->availableQty($l, $batch->id)]);
        }

        return view('production.show', [
            'batch' => $batch, 'open' => $open, 'steps' => ProductionWorkflow::STEPS, 'stepSignatures' => ProductionWorkflow::STEP_SIGNATURES,
            'problems' => $open && $open->step_no !== 7 && $open->step_no !== 8 ? $this->wf->gateProblems($batch, $open->step_no) : [],
            'calc' => $calc, 'calcConfirmed' => $calc && $calc->signatures()->where('meaning', 'confirmed')->whereNull('invalidated_at')->exists(),
            'lotOptions' => $lotOptions, 'mmpSteps' => $this->wf->applicableMmpSteps($batch),
            'signers' => User::signers(), 'qcReviewers' => User::signers()->filter->isQcReviewer(),
            'people' => Person::where('active', true)->orderBy('full_name')->get(),
            'crew' => $batch->people->pluck('person'),
            'equipmentList' => Equipment::where('active', true)->orderBy('equip_id')->get(),
            'scales' => $batch->equipment->pluck('equipment')->filter->is_scale,
            'step8' => $batch->step(8),
        ]);
    }

    public function complete(Request $r, Batch $batch, int $step)
    {
        $this->wf->completeStep($batch, $step, (array) $r->input('signers', []), $r->user(), (array) $r->input('data', []), (array) $r->input('staff', []));

        return redirect()->route('production.show', $batch)->with('status', "Step {$step} complete.");
    }

    public function startCleaning(Request $r, Batch $batch)
    {
        $i = $this->wf->startCleaning($batch, $r->user());

        return redirect()->route('checklists.show', $i)->with('status', "Changeover cleaning {$i->public_id} started for batch {$batch->batch_number}.");
    }

    public function addEquipment(Request $r, Batch $batch)
    {
        $r->validate(['equipment_id' => ['required', 'exists:equipment,id']]);
        $this->wf->addEquipment($batch, Equipment::findOrFail($r->input('equipment_id')), $r->boolean('condition_ok'), $r->user());

        return $this->ok('Equipment added.');
    }

    public function addPerson(Request $r, Batch $batch)
    {
        $r->validate(['person_id' => ['required', 'exists:people,id']]);
        $this->wf->addPerson($batch, Person::with('trainingRecords')->findOrFail($r->input('person_id')), $r->input('task'), $r->boolean('ppe_ok'), $r->user());

        return $this->ok('Added to the batch crew.');
    }

    public function calculate(Request $r, Batch $batch)
    {
        $this->wf->calculate($batch, array_filter(array_map('intval', (array) $r->input('lots', []))), $r->user());

        return $this->ok('Calculated. A supervisor must confirm it.');
    }

    public function confirmCalc(Request $r, Batch $batch)
    {
        $this->wf->confirmCalculation($batch, $this->signer($r), $r->user());

        return $this->ok('Calculation confirmed.');
    }

    public function pick(Request $r, Batch $batch)
    {
        $d = $r->validate(['mmp_line_id' => ['required', 'exists:mmp_lines,id'], 'material_lot_id' => ['required', 'exists:material_lots,id'], 'qty' => ['required', 'numeric', 'gt:0']]);
        $this->wf->pickLot($batch, MmpLine::with('ingredient')->findOrFail($d['mmp_line_id']), MaterialLot::with('ingredient')->findOrFail($d['material_lot_id']), (float) $d['qty'], $r->user());

        return $this->ok('Lot picked and reserved.');
    }

    private function line(Batch $batch, int $id): BatchMaterialLine
    {
        return BatchMaterialLine::where('batch_id', $batch->id)->with('mmpLine', 'batch', 'ingredient')->findOrFail($id);
    }

    public function weigh(Request $r, Batch $batch, int $line)
    {
        $r->validate(['actual_qty' => ['required', 'numeric', 'min:0']]);
        $this->wf->weigh($this->line($batch, $line), (float) $r->input('actual_qty'), $r->integer('scale_equipment_id') ?: null, $this->signer($r), $r->user());

        return $this->ok('Weight recorded.');
    }

    public function verifyLine(Request $r, Batch $batch, int $line)
    {
        $this->wf->verifyLine($this->line($batch, $line), $this->signer($r), $r->user());

        return $this->ok('Verified.');
    }

    public function reweigh(Request $r, Batch $batch, int $line)
    {
        $this->wf->reweigh($this->line($batch, $line), (string) $r->input('reason'), $r->user());

        return $this->ok('Old weighing voided; weigh again.');
    }

    public function voidPick(Request $r, Batch $batch, int $line)
    {
        $this->wf->voidPick($this->line($batch, $line), (string) $r->input('reason'), $r->user());

        return $this->ok('Pick voided and stock released.');
    }

    public function lineDeviation(Request $r, Batch $batch, int $line)
    {
        $d = $this->wf->openToleranceDeviation($this->line($batch, $line), (string) $r->input('description'), $this->signer($r), $r->user());

        return $this->ok("Deviation {$d->public_id} opened.".($d->requires_qc_approval ? ' A QC reviewer must approve continuing.' : ''));
    }

    public function recordMmpStep(Request $r, Batch $batch, MmpStep $mmpStep)
    {
        $this->wf->recordMmpStep($batch, $mmpStep, $r->only(['actual_value', 'observation', 'performed_at', 'late_entry_reason', 'performed_by_person_id', 'staff']), $this->signer($r), $r->user());

        return $this->ok("MMP step {$mmpStep->step_no} recorded.");
    }

    private function rec(Batch $batch, int $id): BatchMmpStep
    {
        return BatchMmpStep::where('batch_id', $batch->id)->with('batch', 'mmpStep')->findOrFail($id);
    }

    public function verifyMmpStep(Request $r, Batch $batch, int $rec)
    {
        $this->wf->verifyMmpStep($this->rec($batch, $rec), $this->signer($r), $r->user());

        return $this->ok('Verified.');
    }

    public function mmpDeviation(Request $r, Batch $batch, int $rec)
    {
        $d = $this->wf->openMmpDeviation($this->rec($batch, $rec), (string) $r->input('description'), $this->signer($r), $r->user());

        return $this->ok("Deviation {$d->public_id} opened.");
    }

    public function upload(Request $r, Batch $batch)
    {
        $r->validate(['kind' => ['required', 'in:coc,coa,other'], 'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,heic,webp']]);
        $kind = $r->input('kind');
        $target = $kind === 'coc' ? $this->wf->assertStepOpen($batch, 8) : ($kind === 'coa' ? tap($batch, fn () => $this->wf->assertStepOpen($batch, 9)) : $batch);
        AttachmentStore::store($target, $kind, $r->file('file'), $r->user());

        return $this->ok('File uploaded.');
    }

    public function coa(Request $r, Batch $batch)
    {
        $d = $r->validate(['coa_lab' => ['required', 'string', 'max:200'], 'coa_sample_id' => ['required', 'string', 'max:100'],
            'coa_potency' => ['nullable', 'numeric', 'min:0'], 'coa_result' => ['required', 'in:pass,fail'], 'output_metrc_uid' => ['nullable', 'string', 'max:100']]);
        $this->wf->recordCoa($batch, $d, $r->user());

        return $this->ok('COA details saved.');
    }

    public function qc(Request $r, Batch $batch)
    {
        $r->validate(['decision' => ['required', 'in:released,rejected']]);
        $this->wf->qcDecision($batch, $r->input('decision'), $this->signer($r), $r->user(), $r->input('note'));

        $to = \App\Support\Modules::enabled('batchlog') ? route('batch-log.show', $batch) : route('production.show', $batch);

        return redirect($to)->with('status', "Batch {$batch->batch_number} ".($r->input('decision') === 'released' ? 'released by QC.' : 'rejected.'));
    }

    public function void(Request $r, Batch $batch)
    {
        $this->wf->voidBatch($batch, (string) $r->input('reason'), $r->user());

        return redirect()->route('production.index')->with('status', "Batch {$batch->batch_number} voided.");
    }
}
