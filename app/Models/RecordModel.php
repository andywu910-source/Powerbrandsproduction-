<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\HasRetention;
use App\Models\Concerns\NoDelete;
use App\Models\Concerns\Voidable;
use Illuminate\Database\Eloquent\Model;

/** Base for compliance records: no delete, audited, keep-until date, void with reason. */
abstract class RecordModel extends Model
{
    use Audited, HasRetention, NoDelete, Voidable;

    protected $guarded = ['id'];

    /** Fields that may be changed through the Correction form once signed. */
    public array $correctable = [];

    public function signatures()
    {
        return $this->morphMany(Signature::class, 'signable')->orderBy('signed_at')->orderBy('id');
    }

    public function revisions()
    {
        return $this->morphMany(Revision::class, 'record', 'record_type', 'record_id')->orderBy('corrected_at')->orderBy('id');
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'record', 'record_type', 'record_id')->orderBy('id');
    }

    public function validSignature(string $meaning): ?Signature
    {
        return $this->signatures->first(fn ($s) => $s->meaning === $meaning && $s->invalidated_at === null);
    }

    public function hasValidSignature(string $meaning): bool
    {
        return $this->signatures()->where('meaning', $meaning)->whereNull('invalidated_at')->exists();
    }

    public function label(): string
    {
        return $this->public_id ?? (class_basename($this).' #'.$this->id);
    }
}
