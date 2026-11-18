<?php

namespace App\Models;

class MaterialLot extends RecordModel
{
    public const STATUSES = ['quarantine' => 'Quarantine', 'released' => 'Released', 'rejected' => 'Rejected', 'depleted' => 'Used up', 'void' => 'Void'];

    protected $casts = ['received_at' => 'datetime', 'expiration_date' => 'date', 'condition_ok' => 'boolean', 'seals_ok' => 'boolean',
        'attestation_ok' => 'boolean', 'source_records_ok' => 'boolean', 'banned_ingredient_ok' => 'boolean', 'inhaled_allowed_ok' => 'boolean',
        'received_qty' => 'float', 'current_balance' => 'float', 'potency_pct' => 'float'];

    public array $correctable = ['supplier_lot', 'grade', 'metrc_manifest_no', 'metrc_package_uid', 'condition_notes', 'expiration_date', 'potency_pct'];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function ingredient() { return $this->belongsTo(Ingredient::class); }
    public function ledger() { return $this->hasMany(LotLedgerEntry::class)->orderBy('id'); }
    public function reservations() { return $this->hasMany(LotReservation::class); }

    public function openReservedQty(?int $exceptBatchId = null): float
    {
        return (float) $this->reservations()->where('status', 'open')
            ->when($exceptBatchId, fn ($q) => $q->where('batch_id', '!=', $exceptBatchId))->sum('qty');
    }

    public function isExpired(): bool
    {
        return $this->expiration_date !== null && $this->expiration_date->toDateString() < now()->toDateString();
    }

    public function label(): string
    {
        return $this->public_id.' · '.($this->ingredient?->name ?? '').($this->supplier_lot ? ' · lot '.$this->supplier_lot : '');
    }
}
