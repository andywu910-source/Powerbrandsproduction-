<?php

namespace App\Models;

class Deviation extends RecordModel
{
    public const STATUSES = ['open' => 'Open', 'approved_to_continue' => 'QC approved continuing', 'closed' => 'Closed', 'void' => 'Void'];

    protected $casts = ['active_ingredient' => 'boolean', 'requires_qc_approval' => 'boolean', 'closed_at' => 'datetime'];
    public array $correctable = ['description', 'impact', 'root_cause', 'corrective_action', 'disposition'];

    public function batch() { return $this->belongsTo(Batch::class); }
    public function lot() { return $this->belongsTo(MaterialLot::class, 'material_lot_id'); }

    public function isOpened(): bool { return $this->hasValidSignature('opened'); }

    /** Lets the batch continue past the problem. */
    public function allowsContinue(): bool
    {
        if ($this->isVoid() || ! $this->isOpened()) {
            return false;
        }

        return ! $this->requires_qc_approval || in_array($this->status, ['approved_to_continue', 'closed'], true);
    }
}
