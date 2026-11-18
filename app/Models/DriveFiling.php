<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

/** Drive outbox: one row per thing to append to a Google Sheet. */
class DriveFiling extends Model
{
    use NoDelete;

    protected $guarded = ['id'];
    protected $casts = ['row' => 'array', 'filed_at' => 'datetime'];
}
