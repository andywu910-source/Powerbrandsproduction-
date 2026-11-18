<?php

namespace App\Models;

class Supplier extends RecordModel
{
    protected $casts = ['approved' => 'boolean', 'active' => 'boolean'];
    public function label(): string { return $this->name; }
}
