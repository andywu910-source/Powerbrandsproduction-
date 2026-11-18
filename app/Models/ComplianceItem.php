<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/** A Compliance Checklist template item (weekly or monthly). */
class ComplianceItem extends Model
{
    use Audited;

    public const FREQUENCIES = ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annual' => 'Annual'];

    protected $guarded = ['id'];

    protected $casts = ['hidden' => 'boolean', 'standing' => 'boolean'];

    public function entries() { return $this->hasMany(ComplianceEntry::class, 'item_id'); }
}
