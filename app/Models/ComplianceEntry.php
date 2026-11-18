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
}
