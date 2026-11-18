<?php

namespace App\Services\Inventory;

use App\Models\Ingredient;
use App\Models\LotLedgerEntry;
use App\Models\LotReservation;
use App\Models\MaterialLot;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Phase 2b placeholder. When Andy connects Distru (a key with inventory read, and
 * write if consumption should post back), this will read Distru batches/packages
 * from https://app.distru.com/public/v1 and mirror them into material_lots
 * (source = 'distru', external_ref = Distru id), keeping the local ledger as the
 * audit trail. Until then, selecting it fails loudly instead of guessing.
 */
class DistruInventorySource implements InventorySource
{
    public function name(): string { return 'distru'; }

    private function notYet(): never
    {
        throw new RuntimeException('Distru is not connected yet. Raw-material stock comes from the Receiving tab.');
    }

    public function listLots(Ingredient $ingredient, ?int $forBatchId = null): Collection { $this->notYet(); }
    public function getLot(int $id): ?MaterialLot { $this->notYet(); }
    public function availableQty(MaterialLot $lot, ?int $forBatchId = null): float { $this->notYet(); }
    public function reserve(MaterialLot $lot, float $qty, int $batchId, ?int $lineId, User $by): LotReservation { $this->notYet(); }
    public function consume(MaterialLot $lot, float $qty, string $refType, int $refId, User $by, ?int $signatureId = null): LotLedgerEntry { $this->notYet(); }
    public function release(int $batchId): int { $this->notYet(); }
}
