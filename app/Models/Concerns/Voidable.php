<?php

namespace App\Models\Concerns;

use App\Exceptions\GateFailed;
use App\Models\User;
use App\Services\Audit;
use App\Services\DriveOutbox;

/** Wrong records are voided with a reason, never deleted. They stay visible, marked VOID. */
trait Voidable
{
    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }

    public function void(string $reason, User $by): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw GateFailed::because('A reason is required to void a record.');
        }
        if ($this->isVoid()) {
            throw GateFailed::because('This record is already void.');
        }
        $this->void_reason = $reason;
        $this->voided_by = $by->id;
        $this->voided_at = now();
        if (array_key_exists('status', $this->getAttributes())) {
            $this->status = 'void';
        }
        $this->save();
        Audit::log('void', $this, ['reason' => $reason]);
        if (array_key_exists('filing_status', $this->getAttributes()) && $this->filing_status !== 'none') {
            DriveOutbox::enqueue($this, 'void', ['VOID', $reason, $by->name]);
        }
    }

    public function scopeNotVoid($q)
    {
        return $q->whereNull($this->getTable().'.voided_at');
    }
}
