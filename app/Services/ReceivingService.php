<?php

namespace App\Services;

use App\Exceptions\GateFailed;
use App\Models\Attachment;
use App\Models\Ingredient;
use App\Models\MaterialLot;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\LotLedger;
use App\Support\Units;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receiving: 1 receive (+ second person for cannabis inputs) -> 2 paperwork (COA,
 * terpene attestation and checks) -> 3 quarantine -> QC release / reject -> 4 balance.
 */
class ReceivingService
{
    public function __construct(private SignatureService $sig) {}

    public function receive(array $d, int $signerId, User $by): MaterialLot
    {
        if ($by->isViewer()) {
            throw GateFailed::because('Read-only accounts cannot receive materials.');
        }
        $ing = Ingredient::findOrFail($d['ingredient_id']);
        $sup = Supplier::findOrFail($d['supplier_id']);
        [$qty, $unit] = Units::toBase((float) $d['qty'], (string) $d['unit']);
        if ($qty <= 0) {
            throw GateFailed::because('Enter the quantity received.');
        }
        if (Units::baseOf($ing->unit) !== $unit) {
            throw GateFailed::because("{$ing->name} is tracked in ".Units::BASE[Units::baseOf($ing->unit)].'; enter the quantity in a matching unit.');
        }
        if ($ing->is_cannabis && (blank($d['metrc_manifest_no'] ?? null) || blank($d['metrc_package_uid'] ?? null))) {
            throw GateFailed::because('Cannabis inputs need the Metrc manifest number and package UID.');
        }

        return DB::transaction(function () use ($d, $ing, $sup, $qty, $unit, $signerId, $by) {
            $lot = MaterialLot::create([
                'public_id' => PublicId::next('RCV'),
                'received_at' => ! empty($d['received_at']) ? Carbon::parse($d['received_at'], config('pbp.site_timezone'))->utc() : now(),
                'supplier_id' => $sup->id, 'ingredient_id' => $ing->id, 'supplier_lot' => $d['supplier_lot'] ?? null, 'grade' => $d['grade'] ?? $ing->grade,
                'received_qty' => $qty, 'unit' => $unit,
                'metrc_manifest_no' => $d['metrc_manifest_no'] ?? null, 'metrc_package_uid' => $d['metrc_package_uid'] ?? null,
                'condition_ok' => ! empty($d['condition_ok']), 'seals_ok' => ! empty($d['seals_ok']), 'condition_notes' => $d['condition_notes'] ?? null,
                'expiration_date' => $d['expiration_date'] ?? null,
                'status' => 'quarantine', 'current_balance' => 0, 'recorded_by_user_id' => $by->id,
            ]);
            if ($lot->expiration_date) {
                $lot->extendRetention($lot->expiration_date);
                $lot->save();
            }
            $this->sig->sign($lot, 'received', $signerId, $by);
            DriveOutbox::enqueue($lot, 'received', [$lot->public_id, $sup->name, $sup->license_no, $ing->name, $lot->grade, $qty, $unit, $lot->metrc_manifest_no, $lot->metrc_package_uid, $lot->condition_ok ? 'OK' : 'NOT OK']);

            return $lot;
        });
    }

    public function verifyReceipt(MaterialLot $lot, int $signerId, User $by): void
    {
        $receiver = $lot->signatures()->where('meaning', 'received')->whereNull('invalidated_at')->pluck('user_id')->all();
        $this->sig->sign($lot, 'verified', $signerId, $by, ['differs_from' => $receiver,
            'differs_message' => 'A second person (not the receiver) must verify cannabis inputs.']);
    }

    public function recordPaperwork(MaterialLot $lot, array $d, int $signerId, User $by): void
    {
        if ($lot->status !== 'quarantine') {
            throw GateFailed::because('Paperwork can only be checked while the lot is in quarantine.');
        }
        DB::transaction(function () use ($lot, $d, $signerId, $by) {
            $lot->update([
                'potency_pct' => isset($d['potency_pct']) && $d['potency_pct'] !== '' ? (float) $d['potency_pct'] : $lot->potency_pct,
                'coa_result' => $d['coa_result'] ?? $lot->coa_result,
                'attestation_ok' => isset($d['attestation_ok']) ? (bool) $d['attestation_ok'] : $lot->attestation_ok,
                'source_records_ok' => isset($d['source_records_ok']) ? (bool) $d['source_records_ok'] : $lot->source_records_ok,
                'banned_ingredient_ok' => isset($d['banned_ingredient_ok']) ? (bool) $d['banned_ingredient_ok'] : $lot->banned_ingredient_ok,
                'inhaled_allowed_ok' => isset($d['inhaled_allowed_ok']) ? (bool) $d['inhaled_allowed_ok'] : $lot->inhaled_allowed_ok,
            ]);
            $this->sig->sign($lot, 'paperwork', $signerId, $by);
        });
    }

    public function releaseProblems(MaterialLot $lot): array
    {
        $lot->loadMissing('ingredient');
        $ing = $lot->ingredient;
        $p = [];
        if ($lot->status !== 'quarantine') {
            $p[] = 'Only a lot in quarantine can be released.';
        }
        if (! $lot->condition_ok || ! $lot->seals_ok) {
            $p[] = 'Condition or seals were not OK at receipt.';
        }
        if ($ing->is_cannabis && ! $lot->hasValidSignature('verified')) {
            $p[] = 'Cannabis input: a second person must verify the receipt.';
        }
        if (! $lot->hasValidSignature('paperwork')) {
            $p[] = 'Paperwork has not been checked and signed.';
        }
        $has = fn ($kind) => Attachment::where('record_type', $lot->getMorphClass())->where('record_id', $lot->id)->where('kind', $kind)->exists();
        if ($ing->requires_coa) {
            if (! $has('coa')) $p[] = 'Upload the COA.';
            if ($lot->coa_result !== 'pass') $p[] = 'The COA must be marked as passing.';
            if ($ing->kind === 'cannabis_active' && ! $lot->potency_pct) $p[] = 'Enter the potency from the COA.';
        }
        if ($ing->requires_attestation) {
            if (! $has('attestation')) $p[] = 'Upload the supplier ingredient attestation (Appendix 6-D).';
            if (! $lot->attestation_ok) $p[] = 'Confirm the attestation is complete.';
            if (! $lot->source_records_ok) $p[] = 'Confirm the terpene source records are on file.';
            if (! $lot->banned_ingredient_ok) $p[] = 'Confirm the banned-ingredient check.';
            if (! $lot->inhaled_allowed_ok) $p[] = 'Confirm the inhaled-ingredient allowed-list check.';
        }
        if ($lot->isExpired()) {
            $p[] = 'The lot is expired.';
        }

        return $p;
    }

    public function release(MaterialLot $lot, int $signerId, User $by, ?string $note = null): void
    {
        if ($p = $this->releaseProblems($lot)) {
            throw new GateFailed($p);
        }
        DB::transaction(function () use ($lot, $signerId, $by, $note) {
            $this->sig->sign($lot, 'qc_release', $signerId, $by, ['qc' => true]);
            $lot->update(['status' => 'released', 'qc_note' => $note]);
            LotLedger::post($lot, 'receipt', (float) $lot->received_qty, $by, 'Opening balance at QC release', null, null, null);
            DriveOutbox::enqueue($lot->fresh(), 'released', [$lot->public_id, $note]);
        });
    }

    public function reject(MaterialLot $lot, int $signerId, User $by, string $note): void
    {
        if ($lot->status !== 'quarantine') {
            throw GateFailed::because('Only a lot in quarantine can be rejected.');
        }
        if (trim($note) === '') {
            throw GateFailed::because('Say why the lot is rejected.');
        }
        DB::transaction(function () use ($lot, $signerId, $by, $note) {
            $this->sig->sign($lot, 'qc_reject', $signerId, $by, ['qc' => true]);
            $lot->update(['status' => 'rejected', 'qc_note' => $note]);
            DriveOutbox::enqueue($lot, 'rejected', [$lot->public_id, $note]);
        });
    }

    public function adjust(MaterialLot $lot, string $type, float $qty, string $unit, string $reason, int $signerId, User $by): void
    {
        if (! in_array($type, ['adjust_up', 'adjust_down', 'waste', 'return'], true)) {
            throw GateFailed::because('Choose the kind of change.');
        }
        if (! in_array($lot->status, ['released', 'depleted'], true)) {
            throw GateFailed::because('Stock can only be changed on a released lot.');
        }
        [$q, $u] = Units::toBase(abs($qty), $unit);
        if ($u !== $lot->unit) {
            throw GateFailed::because("This lot is tracked in {$lot->unit}.");
        }
        DB::transaction(function () use ($lot, $type, $q, $reason, $signerId, $by) {
            $sig = $this->sig->sign($lot, 'adjusted', $signerId, $by);
            $delta = $type === 'adjust_up' ? $q : -$q;
            LotLedger::post($lot, $type === 'adjust_up' || $type === 'adjust_down' ? 'adjust' : $type, $delta, $by, $reason ?: null, null, null, $sig->id);
            DriveOutbox::enqueue($lot->fresh(), 'stock_'.$type, [$lot->public_id, $delta, $reason]);
        });
    }
}
