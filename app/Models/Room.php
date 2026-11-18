<?php

namespace App\Models;

class Room extends RecordModel
{
    protected $casts = ['active' => 'boolean'];
    public function label(): string { return $this->room_no.' – '.$this->name; }
}
