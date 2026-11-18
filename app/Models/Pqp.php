<?php

namespace App\Models;

class Pqp extends RecordModel
{
    protected $casts = ['effective_date' => 'date', 'approved_at' => 'datetime'];
    public array $correctable = ['title', 'effective_date', 'body'];
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
