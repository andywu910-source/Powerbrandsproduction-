<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookkeepingStatement extends Model
{
    protected $guarded = ['id'];

    public function week()
    {
        return $this->belongsTo(BookkeepingWeek::class, 'bookkeeping_week_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
