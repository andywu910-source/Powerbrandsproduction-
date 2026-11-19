<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookkeepingSend extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'recipients' => 'array',
        'invoices' => 'array',
        'sent_at' => 'datetime',
    ];

    public function week()
    {
        return $this->belongsTo(BookkeepingWeek::class, 'bookkeeping_week_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
