<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Batch;
use App\Models\ChecklistInstance;
use App\Models\DriveFiling;
use App\Models\LotLedgerEntry;
use App\Models\Person;
use App\Models\Revision;
use App\Models\Signature;
use App\Support\Units;

/** Read-only batch summary built straight from the signed records (no second copy). */
class BatchSummaryService
{
    public function build(Batch $b): array
    {
        $b->load(['product.brand', 'mmp.lines.ingredient', 'mmp.pqp', 'room', 'steps', 'people.person', 'equipment.equipment',
            'materialLines.lot.supplier', 'materialLines.ingredient', 'materialLines.deviation', 'mmpStepRecords.mmpStep', 'mmpStepRecords.deviation', 'deviations', 'qcReviewer']);
        $tz = config('pbp.site_timezone');
        $fmt = fn ($d) => $d ? $d->copy()->timezone($tz)->format('M j, Y g:i A') : '';
        $sigs = function ($model) use ($fmt) {
            return Signature::with('user')->where('signable_type', $model->getMorphClass())->where('signable_id', $model->getKey())->orderBy('signed_at')->get()
                ->map(fn ($s) => ['meaning' => $s->meaning_text, 'name' => $s->user->name, 'at' => $fmt($s->signed_at), 'invalid' => $s->invalidated_at !== null])->all();
        };
        $people = Person::pluck('full_name', 'id');
        $calc = $b->currentCalculation();
        $planned = collect($calc?->results ?? [])->keyBy('mmp_line_id');

        $steps = $b->steps->map(fn ($s) => [
            'no' => $s->step_no, 'title' => ProductionWorkflow::STEPS[$s->step_no][1], 'status' => $s->status, 'completed_at' => $fmt($s->completed_at),
            'signatures' => $sigs($s), 'staff' => collect($s->data['staff_person_ids'] ?? [])->map(fn ($id) => $people[$id] ?? '#'.$id)->all(),
        ])->all();

        $materials = $b->materialLines->map(fn ($l) => [
            'ingredient' => $l->ingredient->name, 'planned' => Units::display($l->planned_qty, $l->unit),
            'actual' => $l->actual_qty !== null ? Units::display($l->actual_qty, $l->unit) : '', 'variance' => $l->variance_pct, 'tolerance' => $l->tolerance_pct,
            'within' => $l->within_tolerance, 'lot' => $l->lot->public_id, 'supplier_lot' => $l->lot->supplier_lot, 'supplier' => $l->lot->supplier?->name,
            'metrc' => $l->metrc_package_uid, 'status' => $l->status, 'void_reason' => $l->void_reason, 'deviation' => $l->deviation?->public_id, 'signatures' => $sigs($l),
        ])->all();

        $ledger = LotLedgerEntry::with('lot')->where('ref_type', (new \App\Models\BatchMaterialLine)->getMorphClass())->whereIn('ref_id', $b->materialLines->pluck('id'))->get()
            ->map(fn ($e) => ['lot' => $e->lot->public_id, 'qty' => Units::display($e->qty_delta, $e->lot->unit), 'balance_after' => Units::display($e->balance_after, $e->lot->unit), 'at' => $fmt($e->created_at)])->all();

        $mmpSteps = $b->mmpStepRecords->map(fn ($r) => [
            'no' => $r->step_no, 'stage' => $r->stage_code, 'instruction' => $r->mmpStep->instruction, 'parameter' => $r->mmpStep->parameter,
            'limits' => $r->mmpStep->hasLimits() ? trim(($r->mmpStep->limit_min ?? '').'–'.($r->mmpStep->limit_max ?? '').' '.$r->mmpStep->unit) : '',
            'actual' => $r->actual_value, 'observation' => $r->observation, 'within' => $r->within_limit, 'performed_at' => $fmt($r->performed_at),
            'staff' => collect($r->staff_person_ids ?? [])->map(fn ($id) => $people[$id] ?? '#'.$id)->all(), 'status' => $r->status,
            'deviation' => $r->deviation?->public_id, 'late' => $r->late_entry_reason, 'signatures' => $sigs($r),
            'revisions' => Revision::where('record_type', $r->getMorphClass())->where('record_id', $r->id)->get()->map(fn ($v) => ['field' => $v->field, 'old' => $v->old_value, 'new' => $v->new_value, 'reason' => $v->reason, 'initials' => $v->initials, 'at' => $fmt($v->corrected_at)])->all(),
        ])->all();

        $pack = (array) ($b->step(7)?->data ?? []);
        $lab = (array) ($b->step(8)?->data ?? []);
        $cleaning = ChecklistInstance::with('template')->where('batch_id', $b->id)->get()->map(fn ($i) => ['id' => $i->public_id, 'status' => $i->status, 'signatures' => $sigs($i)])->all();
        $files = Attachment::where(function ($q) use ($b) {
            $q->where(fn ($w) => $w->where('record_type', $b->getMorphClass())->where('record_id', $b->id))
                ->orWhere(fn ($w) => $w->where('record_type', (new \App\Models\BatchStep)->getMorphClass())->whereIn('record_id', $b->steps->pluck('id')));
        })->get()->map(fn ($a) => ['id' => $a->id, 'kind' => $a->kind, 'filename' => $a->filename, 'sha256' => $a->sha256])->all();
        $filing = DriveFiling::where('record_type', $b->getMorphClass())->where('record_id', $b->id)->get();

        // QC review/release (step 9) is signed on the batch itself, not on the step row.
        $qcSigs = $sigs($b);
        foreach ($steps as &$st) {
            if ((int) $st['no'] === 9 && empty($st['signatures'])) {
                $st['signatures'] = $qcSigs;
            }
        }
        unset($st);

        $data = [
            'batch_number' => $b->batch_number, 'bpr_id' => $b->public_id, 'status' => $b->logStatusLabel().($b->status === 'void' ? ' (void)' : ''), 'status_key' => $b->logStatus(), 'record_status' => \App\Models\Batch::STATUSES[$b->status] ?? $b->status,
            'product' => $b->product->name, 'brand' => $b->product->brand?->name, 'family' => $b->product->familyLabel(), 'hardware_sku' => $b->mmp?->hardware_sku ?: $b->product->hardware_sku,
            'mmp' => $b->mmp ? $b->mmp->public_id.' v'.$b->mmp_version : 'None (imported from the Google Sheet)', 'pqp' => $b->mmp?->pqp ? $b->mmp->pqp->public_id.' v'.$b->mmp->pqp->version : '',
            'formulation' => ! $b->mmp ? [] : array_filter(['Infusion' => $b->mmp->infusion_type ? (\App\Models\Mmp::INFUSION_TYPES[$b->mmp->infusion_type] ?? $b->mmp->infusion_type).($b->mmp->infusion_pct ? ' '.$b->mmp->infusion_pct.'%' : '') : null,
                'Terps' => trim(($b->mmp->terp_brand ?? '').' '.($b->mmp->terp_flavor ?? '')) ?: null, 'Mix' => trim(($b->mmp->mix_type ?? '').' '.($b->mmp->mix_spec ?? '')) ?: null]),
            'target_units' => $b->target_units, 'target_potency' => $b->target_potency, 'potency_basis' => $b->mmp?->potency_basis ?? 'pct',
            'batch_size' => $b->batch_size_g ? Units::display($b->batch_size_g, 'g') : '', 'room' => $b->room?->label(), 'line' => $b->line,
            'started_at' => $fmt($b->started_at), 'completed_at' => $fmt($b->completed_at), 'output_metrc_uid' => $b->output_metrc_uid,
            'input_metrc_uids' => collect($materials)->pluck('metrc')->filter()->unique()->values()->all(),
            'units_packed' => $b->units_packed, 'yield_pct' => $b->yield_pct, 'yield_range' => $b->mmp ? $b->mmp->yield_min_pct.'–'.$b->mmp->yield_max_pct.'%' : '',
            'losses' => $b->units_packed !== null ? max(0, (int) $b->target_units - $b->units_packed) : null,
            'packaging' => $pack, 'lab_sampling' => $lab, 'steps' => $steps, 'materials' => $materials, 'ledger' => $ledger, 'mmp_steps' => $mmpSteps,
            'cleaning' => $cleaning, 'crew' => $b->people->map(fn ($p) => ['name' => $p->person->full_name, 'task' => $p->task, 'ppe' => $p->ppe_ok])->all(),
            'equipment' => $b->equipment->map(fn ($e) => ['id' => $e->equipment->equip_id, 'name' => $e->equipment->name, 'cal_due' => $e->calibration_due_on?->format('M j, Y')])->all(),
            'deviations' => $b->deviations->map(fn ($d) => ['id' => $d->public_id, 'title' => $d->title, 'status' => \App\Models\Deviation::STATUSES[$d->status] ?? $d->status,
                'root_cause' => $d->root_cause, 'corrective_action' => $d->corrective_action, 'disposition' => $d->disposition, 'signatures' => $sigs($d)])->all(),
            'coa' => ['lab' => $b->coa_lab, 'sample_id' => $b->coa_sample_id, 'potency' => $b->coa_potency, 'result' => $b->coa_result],
            'qc' => ['decision' => $b->qc_decision, 'reviewer' => $b->qcReviewer?->name, 'note' => $b->qc_note, 'signatures' => $qcSigs],
            'files' => $files, 'retention_until' => (string) $b->retention_until,
            'drive' => $filing->isEmpty() ? 'Nothing filed yet' : ($filing->every(fn ($f) => $f->status === 'filed') ? 'Filed to Drive' : 'Signed – not yet filed'),
            'drive_sheet' => $filing->firstWhere('status', 'filed')?->spreadsheet_id,
            'void_reason' => $b->void_reason,
        ];
        $data['hash'] = hash('sha256', json_encode($data));

        return $data;
    }
}
