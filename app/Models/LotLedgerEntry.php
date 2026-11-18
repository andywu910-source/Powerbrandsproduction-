<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

/** Append-only stock ledger. balance_after can never be below zero. */
class LotLedgerEntry extends Model
{
    use NoDelete;
    protected $table = 'lot_ledger';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['qty_delta' => 'float', 'balance_after' => 'float', 'created_at' => 'datetime'];
    public function lot() { return $this->belongsTo(MaterialLot::class, 'material_lot_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
