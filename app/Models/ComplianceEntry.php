<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/** One item in one period (week Monday / month 1st): done, assignee, completed by, date, note. Every change is audited. */
class ComplianceEntry extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected $casts = ['done' => 'boolean', 'period_start' => 'date', 'completed_on' => 'date'];

    public function item() { return $this->belongsTo(ComplianceItem::class, 'item_id'); }
    public function assignee() { return $this->belongsTo(User::class, 'assignee_user_id'); }
    public function completedBy() { return $this->belongsTo(User::class, 'completed_by_user_id'); }

    /** Sign-offs (immutable; an admin can void one with a reason). The newest valid 'completed' one is the live sign-off. */
    public function signatures() { return $this->morphMany(Signature::class, 'signable')->orderBy('signed_at')->orderBy('id'); }

    public function validSignature(): ?Signature
    {
        return $this->signatures->first(fn ($s) => $s->meaning === 'completed' && $s->invalidated_at === null);
    }

    /** Revisions / re-confirmations (append-only, oldest first). */
    public function revisions() { return $this->morphMany(SignoffRevision::class, 'signable')->orderBy('created_at')->orderBy('id'); }

    /** The revision still waiting for its re-confirmation (a done item whose last revision has not been confirmed), or null. */
    public function pendingRevision(): ?SignoffRevision
    {
        if (! $this->done) {
            return null;
        }
        $last = $this->revisions->whereIn('kind', ['revised', 'confirmed'])->last();

        return $last && $last->kind === 'revised' ? $last : null;
    }
}
