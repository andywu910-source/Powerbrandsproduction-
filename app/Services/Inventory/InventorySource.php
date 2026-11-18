<?php

namespace App\Services\Inventory;

use App\Models\Ingredient;
use App\Models\LotLedgerEntry;
use App\Models\LotReservation;
use App\Models\MaterialLot;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Where raw-material stock comes from. Receiving (this app) is the active source.
 * Distru plugs in here later (Phase 2b) without changing the batching screens.
 */
interface InventorySource
{
    public function name(): string;

    /** Lots that can be picked for a batch right now (released, not expired, stock left). */
    public function listLots(Ingredient $ingredient, ?int $forBatchId = null): Collection;

    public function getLot(int $id): ?MaterialLot;

    /** Balance minus other batches' open reservations. */
    public function availableQty(MaterialLot $lot, ?int $forBatchId = null): float;

    public function reserve(MaterialLot $lot, float $qty, int $batchId, ?int $lineId, User $by): LotReservation;

    public function consume(MaterialLot $lot, float $qty, string $refType, int $refId, User $by, ?int $signatureId = null): LotLedgerEntry;

    /** Release every open reservation for a batch (e.g. batch voided or rejected). */
    public function release(int $batchId): int;
}
