<?php

namespace App\Services\Inventory;

use App\Exceptions\GateFailed;
use App\Models\Ingredient;
use App\Models\LotLedgerEntry;
use App\Models\LotReservation;
use App\Models\MaterialLot;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Stock from the Receiving tab: material_lots + the append-only lot_ledger. */
class ReceivingInventorySource implements InventorySource
{
    public function name(): string { return 'receiving'; }

    public function listLots(Ingredient $ingredient, ?int $forBatchId = null): Collection
    {
        return MaterialLot::with('supplier', 'ingredient')
            ->where('ingredient_id', $ingredient->id)->where('status', 'released')->whereNull('voided_at')
            ->orderBy('received_at')->get()
            ->filter(fn ($l) => ! $l->isExpired() && $this->availableQty($l, $forBatchId) > 0)->values();
    }

    public function getLot(int $id): ?MaterialLot
    {
        return MaterialLot::find($id);
    }

    public function availableQty(MaterialLot $lot, ?int $forBatchId = null): float
    {
        return round((float) $lot->current_balance - $lot->openReservedQty($forBatchId), 4);
    }

    /** Why a lot can't be used, or null. */
    public static function lotBlocker(MaterialLot $lot): ?string
    {
        return match (true) {
            $lot->isVoid() => "Lot {$lot->public_id} is void.",
            $lot->status === 'quarantine' => "Lot {$lot->public_id} is still in quarantine. QC must release it first.",
            $lot->status === 'rejected' => "Lot {$lot->public_id} was rejected and can never be used.",
            $lot->status !== 'released' => "Lot {$lot->public_id} is not released.",
            $lot->isExpired() => "Lot {$lot->public_id} expired on {$lot->expiration_date->format('M j, Y')}.",
            default => null,
        };
    }

    public function reserve(MaterialLot $lot, float $qty, int $batchId, ?int $lineId, User $by): LotReservation
    {
        return DB::transaction(function () use ($lot, $qty, $batchId, $lineId, $by) {
            $lot = MaterialLot::whereKey($lot->id)->lockForUpdate()->first();
            if ($why = self::lotBlocker($lot)) {
                throw GateFailed::because($why);
            }
            if ($qty <= 0) {
                throw GateFailed::because('The amount must be more than zero.');
            }
            $avail = $this->availableQty($lot, $batchId);
            $mine = (float) $lot->reservations()->where('status', 'open')->where('batch_id', $batchId)->sum('qty');
            if ($qty + $mine > $avail + 0.00001) {
                throw GateFailed::because("Not enough left in lot {$lot->public_id}: ".round($avail - $mine, 4)." {$lot->unit} available after other batches' reservations.");
            }
            $r = LotReservation::create(['material_lot_id' => $lot->id, 'batch_id' => $batchId, 'batch_material_line_id' => $lineId, 'qty' => $qty, 'status' => 'open', 'user_id' => $by->id]);
            Audit::log('reserve', $lot, ['qty' => $qty, 'batch_id' => $batchId]);

            return $r;
        });
    }

    public function consume(MaterialLot $lot, float $qty, string $refType, int $refId, User $by, ?int $signatureId = null): LotLedgerEntry
    {
        return LotLedger::post($lot, 'consume', -abs($qty), $by, null, $refType, $refId, $signatureId);
    }

    public function release(int $batchId): int
    {
        return LotReservation::where('batch_id', $batchId)->where('status', 'open')->update(['status' => 'released', 'updated_at' => now()]);
    }
}
