<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

/** Indexed link key of an entry (Metrc UID, batch, equipment, room, employee) for the Related logs panel. */
class LogEntryLink extends Model
{
    use NoDelete;

    public const KINDS = ['metrc_uid' => 'Metrc UID', 'batch' => 'Batch', 'equipment' => 'Equipment', 'room' => 'Room', 'employee' => 'Employee'];

    protected $guarded = ['id'];

    public function entry() { return $this->belongsTo(LogEntry::class, 'log_entry_id'); }
}
