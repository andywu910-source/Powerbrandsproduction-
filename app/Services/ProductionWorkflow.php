<?php

namespace App\Services;

use App\Exceptions\GateFailed;
use App\Models\Attachment;
use App\Models\Batch;
use App\Models\BatchCalculation;
use App\Models\BatchEquipment;
use App\Models\BatchMaterialLine;
use App\Models\BatchMmpStep;
use App\Models\BatchPerson;
use App\Models\BatchStep;
use App\Models\ChecklistInstance;
use App\Models\ChecklistTemplate;
use App\Models\Deviation;
use App\Models\Equipment;
use App\Models\MaterialLot;
use App\Models\Mmp;
use App\Models\MmpLine;
use App\Models\MmpStep;
use App\Models\Person;
use App\Models\Product;
use App\Models\ProcessTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Inventory\InventorySource;
use App\Services\Inventory\ReceivingInventorySource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The 9-step gated batch workflow. Every rule is checked here, on the server,
 * inside a transaction. A step can only be worked on when every step before it is
 * complete; there is no "skip", for admins too.
 */
class ProductionWorkflow
{
    public const STEPS = [
        1 => ['setup', 'Batch setup', 'Product, approved recipe (MMP), batch size, target potency and batch number.'],
        2 => ['preop_clean', 'Pre-op cleaning', 'Changeover cleaning checklist for the room, line and equipment, done and verified.'],
        3 => ['equip_check', 'Equipment check', 'Every scale and machine for this batch is calibrated, maintained and approved.'],
        4 => ['personnel', 'People & PPE', 'Everyone on the batch has current training and the right PPE.'],
        5 => ['batching', 'Material batching', 'Calculator, pick released lots, weigh, verify, tolerance check, stock deducted.'],
        6 => ['mmp_steps', 'Manufacturing steps', 'Each step for this product family done in order, with in-process checks.'],
        7 => ['packaging', 'Packaging & labeling', 'Units packed, labels reconciled, yield within range.'],
        8 => ['lab_sampling', 'Lab sampling', 'Lab sampler visit, chain of custody, retained samples.'],
        9 => ['qc_release', 'QC review & release', 'COA passing, every step signed, deviations closed, independent reviewer.'],
    ];

    /** Signatures collected when a step is completed. */
    public const STEP_SIGNATURES = [
        1 => [['issued', 'Batch issued by (supervisor)', 'supervisor']],
        2 => [['completed', 'Pre-op cleaning confirmed by', 'any']],
        3 => [['performed', 'Equipment checked by', 'any'], ['verified', 'Verified by (supervisor, different person)', 'supervisor']],
        4 => [['approved', 'Crew & PPE approved by (supervisor)', 'supervisor']],
        5 => [['completed', 'Batching complete (supervisor)', 'supervisor']],
        6 => [['completed', 'Manufacturing steps complete', 'any']],
        7 => [['performed', 'Packed & reconciled by', 'any'], ['verified', 'Verified by (different person)', 'any']],
        8 => [['sampled', 'Our employee present at sampling', 'any']],
    ];

    /** Steps where the people who did the work are named (Andy: names, not headcount). */
    public const STAFF_STEPS = [2, 3, 5, 6, 7, 8];

    public function __construct(
        private SignatureService $sig,
        private BatchCalculator $calc,
        private InventorySource $inventory,
        private ChecklistService $checklists,
        private DeviationService $deviations,
    ) {}

    // ---------------------------------------------------------------- step 1

    public function setupProblems(?Product $product, ?Mmp $mmp, int $units, ?float $potency): array
    {
        $p = [];
        if (! $product || ! $product->active) {
            return ['Pick an active product.'];
        }
        if ($units > \App\Support\OcmSampling::MAX_UNITS) {
            $p[] = \App\Support\OcmSampling::TOO_BIG;
        }
        if (! $mmp || $mmp->product_id !== $product->id) {
            $p[] = "No approved MMP for {$product->name}. Leo must approve one first.";
        } else {
            if ($mmp->status !== 'approved' || ! $mmp->isCurrent()) {
                $p[] = "MMP {$mmp->public_id} v{$mmp->version} is not the current approved version.";
            }
            if (! $mmp->pqp || $mmp->pqp->status !== 'approved') {
                $p[] = "MMP {$mmp->public_id} has no approved Product Quality Plan (PQP).";
            }
            if ($potency !== null && (($mmp->potency_min !== null && $potency < $mmp->potency_min) || ($mmp->potency_max !== null && $potency > $mmp->potency_max))) {
                $p[] = "Target potency {$potency} is outside the recipe's range ({$mmp->potency_min}–{$mmp->potency_max}).";
            }
        }
        if ($units <= 0) {
            $p[] = 'Enter the number of units.';
        }
        if (! ProcessTemplate::forFamily($product->family)) {
            $p[] = "{$product->name} has no product family / process template set.";
        }

        return $p;   // brand / product codes are only needed for the optional "Suggest" number
    }

    /**
     * Incoming (Andy, Nov 17): a planned job that has not started. It is a batch row with status "planned",
     * the typed batch number (unique, fixed from now on) and the product; planned units / date are optional.
     * No recipe, no steps and none of the start checks: those run in startIncoming().
     */
    public function createIncoming(Product $product, User $by, array $opts = []): Batch
    {
        $this->assertCanWrite($by);
        if (! $product->active) {
            throw GateFailed::because('Pick an active product.');
        }
        $number = trim((string) ($opts['batch_number'] ?? ''));
        if ($why = BatchNumberGenerator::typedProblem($number)) {
            throw GateFailed::because($why);
        }

        return DB::transaction(function () use ($product, $by, $opts, $number) {
            if ($why = BatchNumberGenerator::typedProblem($number)) {
                throw GateFailed::because($why);
            }
            $batch = Batch::create([
                'public_id' => PublicId::next('BPR'), 'batch_number' => $number, 'batch_number_override_reason' => null,
                'product_id' => $product->id, 'target_units' => $opts['planned_units'] ?? null, 'planned_date' => $opts['planned_date'] ?? null,
                'status' => 'planned', 'log_status' => 'incoming', 'current_step' => 1, 'recorded_by_user_id' => $by->id,
            ]);
            Audit::log('batch_incoming', $batch, ['batch_number' => $number, 'product' => $product->name, 'planned_units' => $batch->target_units,
                'planned_date' => $opts['planned_date'] ?? null]);

            return $batch;
        });
    }

    /** "Start this job": Incoming -> In production. Runs every normal start check, then opens step 1. */
    public function startIncoming(Batch $batch, int $units, ?float $potency, User $by, array $opts = []): Batch
    {
        $this->assertCanWrite($by);
        if (! $batch->isIncoming()) {
            throw GateFailed::because("Batch {$batch->batch_number} is not an Incoming job.");
        }
        $product = $batch->product()->first();
        $mmp = $product->currentMmp();
        if ($p = $this->setupProblems($product, $mmp, $units, $potency)) {
            throw new GateFailed($p);
        }

        return DB::transaction(function () use ($batch, $product, $mmp, $units, $potency, $by, $opts) {
            $batch = Batch::whereKey($batch->id)->lockForUpdate()->first();
            if (! $batch->isIncoming()) {
                throw GateFailed::because("Batch {$batch->batch_number} was already started.");
            }
            $batch->update([
                'mmp_id' => $mmp->id, 'mmp_version' => $mmp->version, 'room_id' => $opts['room_id'] ?? null, 'line' => $opts['line'] ?? null,
                'target_units' => $units, 'target_potency' => $potency,
                'batch_size_g' => $mmp->unit_fill_mass_g ? round($units * $mmp->unit_fill_mass_g, 4) : null,
                'status' => 'in_process', 'log_status' => 'in_production', 'current_step' => 1, 'started_at' => now(),
            ]);
            Audit::log('batch_log_status', $batch, ['batch_number' => $batch->batch_number, 'old' => 'incoming', 'new' => 'in_production',
                'old_label' => 'Incoming', 'new_label' => 'In production', 'via' => 'start_this_job']);
            foreach (self::STEPS as $no => [$code]) {
                BatchStep::create(['batch_id' => $batch->id, 'step_no' => $no, 'code' => $code,
                    'status' => $no === 1 ? 'open' : 'locked', 'opened_at' => $no === 1 ? now() : null]);
            }

            return $batch->fresh('steps');
        });
    }

    public function start(Product $product, int $units, ?float $potency, User $by, array $opts = []): Batch
    {
        $this->assertCanWrite($by);
        $mmp = $product->currentMmp();
        if ($p = $this->setupProblems($product, $mmp, $units, $potency)) {
            throw new GateFailed($p);
        }
        // The batch number is typed by the person starting the batch (required, any format, trimmed, unique in any case).
        $number = trim((string) ($opts['batch_number'] ?? ''));
        if ($why = BatchNumberGenerator::typedProblem($number)) {
            throw GateFailed::because($why);
        }

        return DB::transaction(function () use ($product, $mmp, $units, $potency, $by, $opts, $number) {
            if ($why = BatchNumberGenerator::typedProblem($number)) {   // checked again inside the transaction
                throw GateFailed::because($why);
            }
            $batch = Batch::create([
                'public_id' => PublicId::next('BPR'), 'batch_number' => $number,
                'batch_number_override_reason' => null,
                'product_id' => $product->id, 'mmp_id' => $mmp->id, 'mmp_version' => $mmp->version,
                'room_id' => $opts['room_id'] ?? null, 'line' => $opts['line'] ?? null,
                'target_units' => $units, 'target_potency' => $potency,
                'batch_size_g' => $mmp->unit_fill_mass_g ? round($units * $mmp->unit_fill_mass_g, 4) : null,
                'status' => 'in_process', 'log_status' => 'in_production', 'current_step' => 1, 'started_at' => now(), 'recorded_by_user_id' => $by->id,
            ]);
            Audit::log('batch_number_typed', $batch, ['batch_number' => $number, 'suggested' => BatchNumberGenerator::peek($product), 'used_suggestion' => $number === BatchNumberGenerator::peek($product)]);
            foreach (self::STEPS as $no => [$code]) {
                BatchStep::create(['batch_id' => $batch->id, 'step_no' => $no, 'code' => $code,
                    'status' => $no === 1 ? 'open' : 'locked', 'opened_at' => $no === 1 ? now() : null]);
            }

            return $batch->fresh('steps');
        });
    }

    // ---------------------------------------------------------------- gating core

    private function assertCanWrite(User $by): void
    {
        if ($by->isViewer()) {
            throw GateFailed::because('Read-only accounts cannot change batch records.');
        }
    }

    /** Throws 409 unless this step is the open one and every earlier step is complete. */
    public function assertStepOpen(Batch $batch, int $stepNo): BatchStep
    {
        if (in_array($batch->status, ['void', 'released', 'rejected'], true)) {
            throw GateFailed::locked("Batch {$batch->batch_number} is ".strtolower(Batch::STATUSES[$batch->status]).' and can no longer change.');
        }
        $steps = BatchStep::where('batch_id', $batch->id)->orderBy('step_no')->get();
        foreach ($steps as $s) {
            if ($s->step_no < $stepNo && $s->status !== 'complete') {
                throw GateFailed::locked("Step {$stepNo} is locked: step {$s->step_no} (".self::STEPS[$s->step_no][1].') must be completed first.');
            }
        }
        $step = $steps->firstWhere('step_no', $stepNo);
        if (! $step || $step->status !== 'open') {
            throw GateFailed::locked($step && $step->status === 'complete' ? "Step {$stepNo} is already complete." : "Step {$stepNo} is locked.");
        }

        return $step;
    }

    /** Everything still stopping a step from being completed. */
    public function gateProblems(Batch $batch, int $stepNo, array $data = []): array
    {
        $batch->refresh();
        $step = $batch->step($stepNo);

        return match ($stepNo) {
            1 => $this->setupProblems($batch->product, $batch->mmp, $batch->target_units, $batch->target_potency),
            2 => $this->cleaningProblems($batch, $step),
            3 => $this->equipmentProblems($batch),
            4 => $this->personnelProblems($batch),
            5 => $this->batchingProblems($batch),
            6 => $this->mmpStepProblems($batch),
            7 => $this->packagingProblems($batch, $data),
            8 => $this->samplingProblems($batch, $step, $data),
            9 => $this->qcProblems($batch, null),
            default => ['Unknown step.'],
        };
    }

    /**
     * Complete steps 1–8. $signers = [meaning => user id], $staff = person ids.
     */
    public function completeStep(Batch $batch, int $stepNo, array $signers, User $by, array $data = [], array $staff = []): BatchStep
    {
        $this->assertCanWrite($by);
        if ($stepNo === 9) {
            throw GateFailed::because('Step 9 is completed by the QC decision.');
        }

        return DB::transaction(function () use ($batch, $stepNo, $signers, $by, $data, $staff) {
            $batch = Batch::whereKey($batch->id)->lockForUpdate()->first();
            $step = $this->assertStepOpen($batch, $stepNo);
            if ($p = $this->gateProblems($batch, $stepNo, $data)) {
                throw new GateFailed($p);
            }
            if (in_array($stepNo, self::STAFF_STEPS, true)) {
                $staff = $this->validateStaff($batch, $stepNo, $staff);
            }

            $signedIds = [];
            foreach (self::STEP_SIGNATURES[$stepNo] as [$meaning, , $who]) {
                $rules = $who === 'supervisor' ? ['roles' => [User::ROLE_ADMIN, User::ROLE_SUPERVISOR]] : [];
                if ($meaning === 'verified') {
                    $rules['differs_from'] = $signedIds;
                    $rules['differs_message'] = 'The verifier must be a different person from who performed the step.';
                }
                $this->sig->sign($step, $meaning, $signers[$meaning] ?? null, $by, $rules);
                $signedIds[] = (int) ($signers[$meaning] ?? 0);
            }

            $stepData = array_merge((array) $step->data, $this->cleanData($stepNo, $data));
            if ($staff) {
                $stepData['staff_person_ids'] = $staff;
            }
            $step->update(['status' => 'complete', 'completed_at' => now(), 'data' => $stepData]);

            if ($stepNo === 5) {
                $this->postConsumption($batch, $by);
            }
            if ($stepNo === 7) {
                $batch->update(['units_packed' => (int) $data['units_packed'], 'yield_pct' => $this->yieldPct($batch, (int) $data['units_packed'])]);
            }

            $next = BatchStep::where('batch_id', $batch->id)->where('step_no', $stepNo + 1)->first();
            $next?->update(['status' => 'open', 'opened_at' => now()]);
            $batch->update(['current_step' => $stepNo + 1, 'status' => $stepNo === 8 ? 'in_qc' : $batch->status]);
            DriveOutbox::enqueue($batch, 'step_'.$stepNo.'_complete', [self::STEPS[$stepNo][1], json_encode($stepData)]);

            return $step->fresh();
        });
    }

    private function cleanData(int $stepNo, array $data): array
    {
        $keys = match ($stepNo) {
            7 => ['label_version', 'units_packed', 'labels_issued', 'labels_used', 'labels_destroyed', 'labels_returned', 'deviation_id', 'notes'],
            8 => ['sampled_at', 'lab_name', 'sampler_name', 'video_id', 'retained_qty', 'retained_location', 'notes'],
            default => ['notes'],
        };

        return array_intersect_key($data, array_flip($keys));
    }

    /** Named staff for a step: must be on the batch crew (step 4) and active. */
    private function validateStaff(Batch $batch, int $stepNo, array $staff): array
    {
        $staff = array_values(array_unique(array_filter(array_map('intval', $staff))));
        if (! $staff) {
            throw GateFailed::because('Pick everyone (by name) who worked on this step.');
        }
        $people = Person::whereIn('id', $staff)->where('active', true)->pluck('id')->all();
        if (count($people) !== count($staff)) {
            throw GateFailed::because('One of the people picked is not active.');
        }
        if ($stepNo > 4) {
            $crew = $batch->people()->pluck('person_id')->map(fn ($v) => (int) $v)->all();
            if ($missing = array_diff($staff, $crew)) {
                $names = Person::whereIn('id', $missing)->pluck('full_name')->implode(', ');
                throw GateFailed::because("{$names} is not on this batch's crew (step 4).");
            }
        }

        return $staff;
    }

    // ---------------------------------------------------------------- step 2

    public function startCleaning(Batch $batch, User $by): ChecklistInstance
    {
        $this->assertCanWrite($by);
        $step = $this->assertStepOpen($batch, 2);
        $current = $step->checklist;
        if ($current && $current->status !== 'void' && ! ($current->status === 'completed' && $this->checklistFailed($current))) {
            throw GateFailed::because("Changeover cleaning {$current->public_id} is already started for this batch.");
        }
        $t = ChecklistTemplate::where('code', 'changeover_clean')->first();
        if (! $t) {
            throw GateFailed::because('The changeover cleaning checklist template is missing. An admin must add it under Checklists → Templates.');
        }
        $inst = $this->checklists->createOnDemand($t, 'batch:'.$batch->id, $batch->id);
        $step->update(['checklist_instance_id' => $inst->id]);

        return $inst;
    }

    public function checklistFailed(ChecklistInstance $i): bool
    {
        return $i->items()->where('within_limit', false)->exists();
    }

    private function cleaningProblems(Batch $batch, BatchStep $step): array
    {
        $i = $step->checklist;
        if (! $i) {
            return ['Start the changeover cleaning checklist for this batch.'];
        }
        if ($i->status !== 'completed') {
            return ["Changeover cleaning {$i->public_id} is not finished and verified yet."];
        }
        if (! $i->hasValidSignature('verified') && $i->version->requiresVerifier()) {
            return ["Changeover cleaning {$i->public_id} has not been verified by a second person."];
        }
        if ($this->checklistFailed($i)) {
            return ["Changeover cleaning {$i->public_id} has a failed item. Re-clean, then start a new changeover checklist."];
        }

        return [];
    }

    // ---------------------------------------------------------------- step 3

    public function addEquipment(Batch $batch, Equipment $eq, bool $conditionOk, User $by): BatchEquipment
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($batch, 3);
        if ($p = $eq->problems()) {
            throw new GateFailed(array_merge($p, ['Swap it or recalibrate it first.']));
        }
        if (! $conditionOk) {
            throw GateFailed::because("Confirm {$eq->equip_id} is in good condition, or don't use it.");
        }
        if ($batch->equipment()->where('equipment_id', $eq->id)->exists()) {
            throw GateFailed::because("{$eq->equip_id} is already on this batch.");
        }

        return BatchEquipment::create(['batch_id' => $batch->id, 'equipment_id' => $eq->id, 'calibration_due_on' => $eq->calibration_due_on,
            'maintenance_due_on' => $eq->maintenance_due_on, 'condition_ok' => true, 'added_by' => $by->id]);
    }

    private function equipmentProblems(Batch $batch): array
    {
        $rows = $batch->equipment()->with('equipment')->get();
        if ($rows->isEmpty()) {
            return ['Add every scale and machine used for this batch.'];
        }
        $p = [];
        foreach ($rows as $r) {
            $p = array_merge($p, $r->equipment->problems());
        }
        $needsScale = $batch->mmp->lines->contains(fn ($l) => $l->scale_mode !== 'by_count');
        if ($needsScale && ! $rows->contains(fn ($r) => $r->equipment->is_scale)) {
            $p[] = 'This batch weighs ingredients, so a scale must be on the equipment list.';
        }
        $scaleTemplate = ChecklistTemplate::where('code', 'scale_check_daily')->first();
        if ($needsScale && $scaleTemplate && ! $scaleTemplate->active) {
            // Nov 19: the Checklists scale check was retired, and later the same day (Andy) the hub's BAL Balance
            // Check too, so there is no daily balance-check gate any more. Scale register problems
            // (calibration, inspection, seal) above still block.
        } elseif ($needsScale && \App\Support\Modules::enabled('checklists') && Setting::get('gate_require_daily_scale_check', true)) {
            if (ChecklistService::isPerScale('scale_check_daily')) {
                // Linked to the scale register: every scale on this batch needs its own check today.
                foreach ($rows->filter(fn ($r) => $r->equipment->is_scale) as $r) {
                    if (! ChecklistService::completedTodayForScale('scale_check_daily', $r->equipment_id)) {
                        $p[] = "Today's scale check for {$r->equipment->equip_id} (Checklists) has not been completed yet.";
                    }
                }
            } elseif (! ChecklistService::completedToday('scale_check_daily')) {
                $p[] = "Today's daily scale check (Checklists) has not been completed yet.";
            }
        }

        return $p;
    }

    // ---------------------------------------------------------------- step 4

    public function addPerson(Batch $batch, Person $person, ?string $task, bool $ppeOk, User $by): BatchPerson
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($batch, 4);
        if (! $person->active) {
            throw GateFailed::because("{$person->full_name} is not active.");
        }
        if ($missing = $person->missingTraining()) {
            throw GateFailed::because("{$person->full_name} can't be added: training missing or expired (".implode(', ', $missing).').');
        }
        if (! $ppeOk) {
            throw GateFailed::because("Confirm {$person->full_name} has the right PPE.");
        }
        if ($batch->people()->where('person_id', $person->id)->exists()) {
            throw GateFailed::because("{$person->full_name} is already on this batch.");
        }

        return BatchPerson::create(['batch_id' => $batch->id, 'person_id' => $person->id, 'task' => $task, 'ppe_ok' => true,
            'training_record_ids' => $person->trainingRecords->pluck('id')->all(), 'added_by' => $by->id]);
    }

    private function personnelProblems(Batch $batch): array
    {
        $rows = $batch->people()->with('person.trainingRecords')->get();
        if ($rows->isEmpty()) {
            return ['Add everyone working on this batch.'];
        }
        $p = [];
        foreach ($rows as $r) {
            if ($m = $r->person->missingTraining()) {
                $p[] = "{$r->person->full_name}: training missing or expired (".implode(', ', $m).').';
            }
            if (! $r->ppe_ok) {
                $p[] = "{$r->person->full_name}: PPE not confirmed.";
            }
        }

        return $p;
    }

    // ---------------------------------------------------------------- step 5 (M1–M6)

    /** M1: run the calculator and freeze the result. */
    public function calculate(Batch $batch, array $lotForLine, User $by): BatchCalculation
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($batch, 5);
        if ($batch->materialLines()->whereNull('voided_at')->exists()) {
            throw GateFailed::because('Lots are already picked for this calculation. Void the picks before recalculating.');
        }
        $mmp = $batch->mmp()->with('lines.ingredient')->first();
        $out = $this->calc->calculate($mmp, $batch->target_units, $batch->target_potency, $lotForLine);

        return DB::transaction(function () use ($batch, $mmp, $out, $by) {
            BatchCalculation::where('batch_id', $batch->id)->update(['superseded' => true]);

            return BatchCalculation::create(['batch_id' => $batch->id, 'mmp_id' => $mmp->id, 'mmp_version' => $mmp->version,
                'target_units' => $batch->target_units, 'target_potency' => $batch->target_potency,
                'inputs' => $out['inputs'], 'results' => $out['results'], 'calculated_by' => $by->id]);
        });
    }

    public function confirmCalculation(Batch $batch, int $signerId, User $by): void
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($batch, 5);
        $calc = $batch->currentCalculation() ?? throw GateFailed::because('Run the calculator first.');
        $this->sig->sign($calc, 'confirmed', $signerId, $by, ['roles' => [User::ROLE_ADMIN, User::ROLE_SUPERVISOR]]);
    }

    private function calcConfirmed(?BatchCalculation $c): bool
    {
        return $c && $c->signatures()->where('meaning', 'confirmed')->whereNull('invalidated_at')->exists();
    }

    /** M2: pick a released lot for a recipe line (reserves the stock). */
    public function pickLot(Batch $batch, MmpLine $line, MaterialLot $lot, float $qty, User $by): BatchMaterialLine
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($batch, 5);
        $calc = $batch->currentCalculation();
        if (! $this->calcConfirmed($calc)) {
            throw GateFailed::because('A supervisor must confirm the calculation before lots are picked.');
        }
        if ($line->mmp_id !== $batch->mmp_id) {
            throw GateFailed::because('That ingredient line is not part of this batch\'s recipe.');
        }
        if ($lot->ingredient_id !== $line->ingredient_id) {
            throw GateFailed::because("Lot {$lot->public_id} is {$lot->ingredient->name}, not {$line->ingredient->name}.");
        }
        if ($why = ReceivingInventorySource::lotBlocker($lot)) {
            throw GateFailed::because($why);
        }
        $planned = collect($calc->results)->firstWhere('mmp_line_id', $line->id)['planned_qty'] ?? 0;
        $already = (float) $batch->materialLines()->where('mmp_line_id', $line->id)->whereNull('voided_at')->sum('planned_qty');
        if ($qty <= 0 || $already + $qty > $planned + 0.005) {
            throw GateFailed::because('Pick at most '.round($planned - $already, 2)." {$line->unit} more for {$line->ingredient->name}.");
        }
        $potencyLot = $calc->inputs['lots'][$line->id]['lot_id'] ?? null;
        if ($line->scale_mode === 'potency_adjusted' && $potencyLot && (int) $potencyLot !== $lot->id) {
            throw GateFailed::because('The calculation used lot '.$calc->inputs['lots'][$line->id]['public_id'].'\'s potency. Use that lot, or recalculate.');
        }

        return DB::transaction(function () use ($batch, $line, $lot, $qty, $by, $calc) {
            $res = $this->inventory->reserve($lot, $qty, $batch->id, null, $by);
            $bml = BatchMaterialLine::create(['batch_id' => $batch->id, 'mmp_line_id' => $line->id, 'ingredient_id' => $line->ingredient_id,
                'batch_calculation_id' => $calc->id, 'planned_qty' => round($qty, 4), 'unit' => $line->unit, 'material_lot_id' => $lot->id,
                'metrc_package_uid' => $lot->metrc_package_uid, 'reservation_id' => $res->id, 'tolerance_pct' => $line->effectiveTolerancePct(), 'status' => 'picked']);
            $res->update(['batch_material_line_id' => $bml->id]);

            return $bml;
        });
    }

    /** M3: weigh / count. */
    public function weigh(BatchMaterialLine $line, float $actual, ?int $scaleId, int $signerId, User $by): void
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($line->batch, 5);
        if ($line->status !== 'picked') {
            throw GateFailed::because('This line is already weighed. Use "Re-weigh" to start it again.');
        }
        if ($actual < 0) {
            throw GateFailed::because('The amount cannot be negative.');
        }
        $isCount = $line->mmpLine->scale_mode === 'by_count' || $line->unit === 'ea';
        if (! $isCount) {
            $scale = $scaleId ? Equipment::find($scaleId) : null;
            if (! $scale || ! $scale->is_scale) {
                throw GateFailed::because('Pick the scale used.');
            }
            if (! $line->batch->equipment()->where('equipment_id', $scale->id)->exists()) {
                throw GateFailed::because("Scale {$scale->equip_id} was not checked in step 3 for this batch.");
            }
            if ($p = $scale->problems()) {
                throw new GateFailed($p);
            }
        }
        $variance = $line->planned_qty > 0 ? round(($actual - $line->planned_qty) / $line->planned_qty * 100, 3) : ($actual == 0 ? 0 : 100);
        $within = abs($variance) <= (float) $line->tolerance_pct + 0.0001;
        DB::transaction(function () use ($line, $actual, $scaleId, $isCount, $variance, $within, $signerId, $by) {
            $line->update(['actual_qty' => round($actual, 4), 'scale_equipment_id' => $isCount ? null : $scaleId,
                'variance_pct' => $variance, 'within_tolerance' => $within, 'status' => 'weighed']);
            $this->sig->sign($line, 'weighed', $signerId, $by);
        });
    }

    /** M4: second person verifies. */
    public function verifyLine(BatchMaterialLine $line, int $signerId, User $by): void
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($line->batch, 5);
        if ($line->status !== 'weighed') {
            throw GateFailed::because('This line has not been weighed yet.');
        }
        $weigher = $line->validSignature('weighed')?->user_id;
        DB::transaction(function () use ($line, $signerId, $by, $weigher) {
            $this->sig->sign($line, 'verified', $signerId, $by, ['differs_from' => [$weigher],
                'differs_message' => 'The person verifying must be different from the person who weighed it.']);
            $line->update(['status' => 'verified']);
        });
    }

    /** Re-weigh: the old line is voided (kept, with reason) and a fresh pick is made. */
    public function reweigh(BatchMaterialLine $line, string $reason, User $by): BatchMaterialLine
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($line->batch, 5);
        if (trim($reason) === '') {
            throw GateFailed::because('Say why it is being re-weighed.');
        }
        if ($line->voided_at || $line->status === 'consumed') {
            throw GateFailed::because('This line cannot be re-weighed.');
        }

        return DB::transaction(function () use ($line, $reason, $by) {
            $line->update(['status' => 'void', 'void_reason' => trim($reason), 'voided_at' => now(), 'voided_by' => $by->id]);
            $new = $line->replicate(['actual_qty', 'variance_pct', 'within_tolerance', 'scale_equipment_id', 'deviation_id', 'void_reason', 'voided_at', 'voided_by']);
            $new->status = 'picked';
            $new->save();
            \App\Models\LotReservation::whereKey($line->reservation_id)->update(['batch_material_line_id' => $new->id]);

            return $new;
        });
    }

    /** Void a pick entirely (frees the reservation). */
    public function voidPick(BatchMaterialLine $line, string $reason, User $by): void
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($line->batch, 5);
        if (trim($reason) === '' || $line->voided_at || $line->status === 'consumed') {
            throw GateFailed::because('A reason is needed, and the line must not be used up already.');
        }
        DB::transaction(function () use ($line, $reason, $by) {
            $line->update(['status' => 'void', 'void_reason' => trim($reason), 'voided_at' => now(), 'voided_by' => $by->id]);
            \App\Models\LotReservation::whereKey($line->reservation_id)->where('status', 'open')->update(['status' => 'released']);
        });
    }

    /** M5: out of tolerance -> open a deviation linked to the line. */
    public function openToleranceDeviation(BatchMaterialLine $line, string $description, int $signerId, User $by): Deviation
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($line->batch, 5);
        if ($line->within_tolerance !== false) {
            throw GateFailed::because('This line is within tolerance; no deviation is needed.');
        }
        $active = $line->mmpLine->line_type === 'cannabis_active';
        $needsQc = Setting::get('tolerance_deviation_qc', 'all') === 'all' || $active;

        return DB::transaction(function () use ($line, $description, $signerId, $by, $active, $needsQc) {
            $d = $this->deviations->open($line->batch, $line, "Out of tolerance: {$line->ingredient->name}",
                $description ?: "Weighed {$line->actual_qty} {$line->unit} vs planned {$line->planned_qty} ({$line->variance_pct}%, allowed ±{$line->tolerance_pct}%).",
                $active, $needsQc, $signerId, $by, $line->material_lot_id);
            $line->update(['deviation_id' => $d->id]);

            return $d;
        });
    }

    private function batchingProblems(Batch $batch): array
    {
        $calc = $batch->currentCalculation();
        if (! $calc) {
            return ['Run the batch calculator.'];
        }
        $p = [];
        if (! $this->calcConfirmed($calc)) {
            $p[] = 'A supervisor must confirm the calculation.';
        }
        $lines = $batch->materialLines()->whereNull('voided_at')->with('ingredient', 'deviation', 'lot')->get();
        foreach ($calc->results as $r) {
            $mine = $lines->where('mmp_line_id', $r['mmp_line_id']);
            $sum = round($mine->sum('planned_qty'), 4);
            if (abs($sum - $r['planned_qty']) > 0.005) {
                $p[] = "{$r['ingredient']}: lots picked for {$sum} of {$r['planned_qty']} {$r['unit']}.";
            }
        }
        foreach ($lines as $l) {
            if ($l->status !== 'verified') {
                $p[] = "{$l->ingredient->name} ({$l->lot->public_id}): ".($l->status === 'picked' ? 'not weighed yet.' : 'not verified by a second person yet.');
            } elseif ($l->within_tolerance === false && ! ($l->deviation && $l->deviation->allowsContinue())) {
                $p[] = "{$l->ingredient->name}: {$l->variance_pct}% off (allowed ±{$l->tolerance_pct}%). Re-weigh, or open a deviation"
                    .($l->deviation && $l->deviation->requires_qc_approval ? ' and get QC approval to continue.' : '.');
            }
            if ($why = ReceivingInventorySource::lotBlocker($l->lot)) {
                $p[] = $why;
            }
        }

        return $p;
    }

    /** M6: post stock usage, all or nothing. */
    private function postConsumption(Batch $batch, User $by): void
    {
        foreach ($batch->materialLines()->whereNull('voided_at')->where('status', 'verified')->get() as $l) {
            $verifier = $l->validSignature('verified');
            \App\Models\LotReservation::whereKey($l->reservation_id)->update(['status' => 'consumed']);
            $entry = $this->inventory->consume($l->lot, (float) $l->actual_qty, $l->getMorphClass(), $l->id, $by, $verifier?->id);
            $l->update(['status' => 'consumed', 'ledger_entry_id' => $entry->id]);
        }
    }

    // ---------------------------------------------------------------- step 6

    public function applicableMmpSteps(Batch $batch)
    {
        return $batch->mmp()->with('steps', 'product')->first()->applicableSteps();
    }

    public function recordMmpStep(Batch $batch, MmpStep $ms, array $in, int $signerId, User $by): BatchMmpStep
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($batch, 6);
        $steps = $this->applicableMmpSteps($batch);
        if (! $steps->contains('id', $ms->id)) {
            throw GateFailed::because('That step is not part of this product\'s process.');
        }
        $records = $batch->mmpStepRecords()->whereNull('voided_at')->get();
        foreach ($steps as $s) {
            if ($s->id === $ms->id) {
                break;
            }
            $r = $records->firstWhere('mmp_step_id', $s->id);
            if (! $r) {
                throw GateFailed::locked("Do MMP step {$s->step_no} first. Steps go in order.");
            }
            if ($s->requires_verifier && $r->status !== 'verified') {
                throw GateFailed::locked("MMP step {$s->step_no} must be verified before the next step.");
            }
            if ($r->within_limit === false && ! ($r->deviation && $r->deviation->allowsContinue())) {
                throw GateFailed::locked("MMP step {$s->step_no} was out of limits. Open and sign a deviation before going on.");
            }
        }
        if ($records->firstWhere('mmp_step_id', $ms->id)) {
            throw GateFailed::because("MMP step {$ms->step_no} is already recorded. Use a correction to change it.");
        }
        $value = isset($in['actual_value']) && $in['actual_value'] !== '' ? (float) $in['actual_value'] : null;
        if ($ms->hasLimits() && $value === null) {
            throw GateFailed::because("Enter the actual {$ms->parameter} for MMP step {$ms->step_no}.");
        }
        $within = $ms->hasLimits() ? (($ms->limit_min === null || $value >= $ms->limit_min) && ($ms->limit_max === null || $value <= $ms->limit_max)) : null;
        $performedAt = ! empty($in['performed_at']) ? Carbon::parse($in['performed_at'], config('pbp.site_timezone'))->utc() : now();
        if ($performedAt->isFuture()) {
            throw GateFailed::because('The time it was done cannot be in the future.');
        }
        $late = $performedAt->diffInMinutes(now()) > (int) Setting::get('late_entry_minutes', 60);
        if ($late && trim((string) ($in['late_entry_reason'] ?? '')) === '') {
            throw GateFailed::because('This is being entered late. Say why.');
        }
        $staff = $this->validateStaff($batch, 6, (array) ($in['staff'] ?? []));
        $performer = ! empty($in['performed_by_person_id']) ? (int) $in['performed_by_person_id'] : ($staff[0] ?? null);

        return DB::transaction(function () use ($batch, $ms, $in, $value, $within, $performedAt, $late, $staff, $performer, $signerId, $by) {
            $r = BatchMmpStep::create(['batch_id' => $batch->id, 'mmp_step_id' => $ms->id, 'step_no' => $ms->step_no, 'stage_code' => $ms->stage_code,
                'actual_value' => $value, 'observation' => $in['observation'] ?? null, 'within_limit' => $within, 'performed_at' => $performedAt,
                'performed_by_person_id' => $performer, 'staff_person_ids' => $staff,
                'status' => $ms->requires_verifier ? 'recorded' : 'verified', 'recorded_by_user_id' => $by->id,
                'late_entry_reason' => $late ? trim((string) $in['late_entry_reason']) : null]);
            $this->sig->sign($r, 'performed', $signerId, $by);

            return $r;
        });
    }

    public function verifyMmpStep(BatchMmpStep $r, int $signerId, User $by): void
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($r->batch, 6);
        if ($r->status !== 'recorded') {
            throw GateFailed::because('This step is not waiting for verification.');
        }
        $performer = $r->validSignature('performed')?->user_id;
        DB::transaction(function () use ($r, $signerId, $by, $performer) {
            $this->sig->sign($r, 'verified', $signerId, $by, ['differs_from' => [$performer],
                'differs_message' => 'The verifier must be a different person from who did the step.']);
            $r->update(['status' => 'verified']);
        });
    }

    public function openMmpDeviation(BatchMmpStep $r, string $description, int $signerId, User $by): Deviation
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($r->batch, 6);
        if ($r->within_limit !== false) {
            throw GateFailed::because('This step is within limits.');
        }

        return DB::transaction(function () use ($r, $description, $signerId, $by) {
            $ms = $r->mmpStep;
            $d = $this->deviations->open($r->batch, $r, "Out of limits: MMP step {$ms->step_no} ({$ms->parameter})",
                $description ?: "Actual {$r->actual_value} {$ms->unit}, limits {$ms->limit_min}–{$ms->limit_max}.", false, false, $signerId, $by);
            $r->update(['deviation_id' => $d->id]);

            return $d;
        });
    }

    private function mmpStepProblems(Batch $batch): array
    {
        $steps = $this->applicableMmpSteps($batch);
        $template = ProcessTemplate::forFamily($batch->product->family);
        $p = [];
        if ($template) {
            foreach ($template->activeStages as $stage) {
                // A non-infused preroll has no infusion stage.
                if ($stage->code === 'infusion' && blank($batch->mmp?->infusion_type)) {
                    continue;
                }
                if (! $steps->contains('stage_code', $stage->code)) {
                    $p[] = "The recipe has no steps for the {$stage->name} stage.";
                }
            }
        }
        if ($steps->isEmpty()) {
            $p[] = 'The recipe has no manufacturing steps for this product family.';
        }
        $records = $batch->mmpStepRecords()->whereNull('voided_at')->with('deviation')->get();
        foreach ($steps as $s) {
            $r = $records->firstWhere('mmp_step_id', $s->id);
            if (! $r) {
                $p[] = "MMP step {$s->step_no} is not done yet.";
            } elseif ($s->requires_verifier && $r->status !== 'verified') {
                $p[] = "MMP step {$s->step_no} is not verified yet.";
            } elseif ($r->within_limit === false && ! ($r->deviation && $r->deviation->allowsContinue())) {
                $p[] = "MMP step {$s->step_no} was out of limits and needs a signed deviation.";
            }
        }

        return $p;
    }

    // ---------------------------------------------------------------- step 7

    public function yieldPct(Batch $batch, int $packed): float
    {
        return $batch->target_units > 0 ? round($packed / $batch->target_units * 100, 2) : 0;
    }

    private function packagingProblems(Batch $batch, array $d): array
    {
        $p = [];
        foreach (['units_packed' => 'units packed', 'labels_issued' => 'labels issued', 'labels_used' => 'labels used', 'labels_destroyed' => 'labels destroyed', 'labels_returned' => 'labels returned'] as $k => $label) {
            if (! isset($d[$k]) || $d[$k] === '' || ! is_numeric($d[$k]) || (int) $d[$k] < 0) {
                $p[] = "Enter the number of {$label}.";
            }
        }
        if (blank($d['label_version'] ?? null)) {
            $p[] = 'Enter the label version used.';
        }
        if ($p) {
            return $p;
        }
        $problem = [];
        if ((int) $d['labels_issued'] !== (int) $d['labels_used'] + (int) $d['labels_destroyed'] + (int) $d['labels_returned']) {
            $problem[] = 'Labels don\'t reconcile: issued must equal used + destroyed + returned.';
        }
        $y = $this->yieldPct($batch, (int) $d['units_packed']);
        $mmp = $batch->mmp;
        if ($y < $mmp->yield_min_pct || $y > $mmp->yield_max_pct) {
            $problem[] = "Yield {$y}% is outside the recipe's range ({$mmp->yield_min_pct}–{$mmp->yield_max_pct}%).";
        }
        if ($problem) {
            $dev = ! empty($d['deviation_id']) ? Deviation::where('batch_id', $batch->id)->find((int) $d['deviation_id']) : null;
            if (! $dev || ! $dev->allowsContinue()) {
                $problem[] = 'Fix the counts, or open and sign a deviation for this batch and pick it here.';

                return $problem;
            }
        }

        return [];
    }

    // ---------------------------------------------------------------- step 8

    private function samplingProblems(Batch $batch, BatchStep $step, array $d): array
    {
        $p = [];
        foreach (['sampled_at' => 'date/time of sampling', 'lab_name' => 'lab name', 'sampler_name' => 'lab sampler\'s name', 'video_id' => 'video ID of the sampling', 'retained_qty' => 'retained sample quantity', 'retained_location' => 'where the retained samples are kept'] as $k => $label) {
            if (blank($d[$k] ?? null)) {
                $p[] = "Enter the {$label}.";
            }
        }
        if (! Attachment::where('record_type', $step->getMorphClass())->where('record_id', $step->id)->where('kind', 'coc')->exists()) {
            $p[] = 'Upload the chain-of-custody form signed by us and the lab sampler.';
        }

        return $p;
    }

    // ---------------------------------------------------------------- step 9

    public function qcProblems(Batch $batch, ?int $reviewerId): array
    {
        $p = [];
        foreach (BatchStep::where('batch_id', $batch->id)->where('step_no', '<', 9)->orderBy('step_no')->get() as $s) {
            if ($s->status !== 'complete') {
                $p[] = "Step {$s->step_no} (".self::STEPS[$s->step_no][1].') is not complete.';
            }
        }
        if (! Attachment::where('record_type', $batch->getMorphClass())->where('record_id', $batch->id)->where('kind', 'coa')->exists()) {
            $p[] = 'Upload the batch COA.';
        }
        if ($batch->coa_result !== 'pass') {
            $p[] = 'The COA must be marked as passing.';
        }
        if ($batch->target_potency && $batch->coa_potency !== null) {
            $pct = $batch->coa_potency / $batch->target_potency * 100;
            $min = (float) Setting::get('coa_min_pct_of_label', 85);
            $max = (float) Setting::get('coa_max_pct_of_label', 115);
            if ($pct < $min || $pct > $max) {
                $p[] = 'COA potency is '.round($pct, 1)."% of the target; it must be {$min}–{$max}%.";
            }
        } elseif ($batch->target_potency) {
            $p[] = 'Enter the potency from the COA.';
        }
        $open = Deviation::where('batch_id', $batch->id)->whereNull('voided_at')->where('status', '!=', 'closed')->pluck('public_id')->all();
        if ($open) {
            $p[] = 'These deviations must be closed first: '.implode(', ', $open).'.';
        }
        if ($reviewerId && in_array($reviewerId, SignatureService::batchWorkerIds($batch), true)) {
            $p[] = 'The QC reviewer worked on this batch and cannot release it. Another QC reviewer must do it.';
        }

        return $p;
    }

    public function recordCoa(Batch $batch, array $d, User $by): void
    {
        $this->assertCanWrite($by);
        $this->assertStepOpen($batch, 9);
        $batch->update(array_intersect_key($d, array_flip(['coa_lab', 'coa_sample_id', 'coa_potency', 'coa_result', 'output_metrc_uid'])));
    }

    public function qcDecision(Batch $batch, string $decision, int $reviewerId, User $by, ?string $note = null): void
    {
        $this->assertCanWrite($by);
        if (! in_array($decision, ['released', 'rejected'], true)) {
            throw GateFailed::because('Choose release or reject.');
        }
        DB::transaction(function () use ($batch, $decision, $reviewerId, $by, $note) {
            $batch = Batch::whereKey($batch->id)->lockForUpdate()->first();
            $step = $this->assertStepOpen($batch, 9);
            $reviewer = User::find($reviewerId);
            if (! $reviewer || ! $reviewer->isQcReviewer()) {
                throw GateFailed::because('Only a QC reviewer (set in Settings) can make the QC decision.');
            }
            if (in_array($reviewerId, SignatureService::batchWorkerIds($batch), true)) {
                throw GateFailed::because("{$reviewer->name} worked on this batch and cannot be its QC reviewer. Another QC reviewer must decide.");
            }
            if ($decision === 'released' && ($p = $this->qcProblems($batch, $reviewerId))) {
                throw new GateFailed($p);
            }
            if ($decision === 'rejected' && trim((string) $note) === '') {
                throw GateFailed::because('Say why the batch is rejected.');
            }
            $this->sig->sign($batch, $decision === 'released' ? 'qc_release' : 'qc_reject', $reviewerId, $by, ['qc' => true]);
            $step->update(['status' => 'complete', 'completed_at' => now(), 'data' => ['decision' => $decision, 'note' => $note]]);
            $batch->update(['status' => $decision, 'qc_decision' => $decision, 'qc_reviewer_user_id' => $reviewerId, 'qc_note' => $note, 'completed_at' => now(), 'log_status' => 'finished']);
            if ($decision === 'rejected') {
                $this->inventory->release($batch->id);
            }
            DriveOutbox::enqueue($batch, 'qc_'.$decision, [$batch->batch_number, $reviewer->name, $note]);
        });
    }

    /** Void a whole batch (supervisor/admin), frees reservations. */
    public function voidBatch(Batch $batch, string $reason, User $by): void
    {
        if (! $by->isSupervisorOrAdmin()) {
            throw GateFailed::because('Only a supervisor or admin can void a batch.');
        }
        if (in_array($batch->status, ['released'], true)) {
            throw GateFailed::because('A released batch cannot be voided. Use a deviation / recall instead.');
        }
        DB::transaction(function () use ($batch, $reason, $by) {
            $batch->void($reason, $by);
            $this->inventory->release($batch->id);
        });
    }
}
