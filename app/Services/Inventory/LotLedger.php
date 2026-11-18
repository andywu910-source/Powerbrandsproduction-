<?php

namespace App\Services\Inventory;

use App\Exceptions\GateFailed;
use App\Models\LotLedgerEntry;
use App\Models\MaterialLot;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;

/** The only way stock changes. One transaction, row lock, never below zero. */
class LotLedger
{
    public const TYPES = ['receipt' => 'Received (opening balance)', 'consume' => 'Used in batch', 'adjust' => 'Adjustment', 'waste' => 'Waste', 'return' => 'Returned', 'sync' => 'Sync'];

    public static function post(MaterialLot $lot, string $type, float $delta, User $by, ?string $reason = null, ?string $refType = null, ?int $refId = null, ?int $signatureId = null): LotLedgerEntry
    {
        if (! isset(self::TYPES[$type])) {
            throw GateFailed::because('Unknown stock entry type.');
        }
        if (in_array($type, ['adjust', 'waste'], true) && trim((string) $reason) === '') {
            throw GateFailed::because('A reason is required for adjustments and waste.');
        }

        return DB::transaction(function () use ($lot, $type, $delta, $by, $reason, $refType, $refId, $signatureId) {
            $lot = MaterialLot::whereKey($lot->id)->lockForUpdate()->first();
            $balance = round((float) $lot->current_balance + $delta, 4);
            if ($balance < 0) {
                throw GateFailed::because("Lot {$lot->public_id} only has ".round((float) $lot->current_balance, 4)." {$lot->unit} left. Stock can never go below zero.");
            }
            $entry = LotLedgerEntry::create([
                'material_lot_id' => $lot->id, 'entry_type' => $type, 'qty_delta' => round($delta, 4), 'balance_after' => $balance,
                'ref_type' => $refType, 'ref_id' => $refId, 'reason' => $reason, 'user_id' => $by->id, 'signature_id' => $signatureId,
                'created_at' => now(),
            ]);
            $lot->current_balance = $balance;
            if ($balance <= 0 && $lot->status === 'released') {
                $lot->status = 'depleted';
            } elseif ($balance > 0 && $lot->status === 'depleted') {
                $lot->status = 'released';
            }
            $lot->save();
            Audit::log('ledger', $lot, ['type' => $type, 'delta' => $delta, 'balance_after' => $balance]);

            return $entry;
        });
    }
}
