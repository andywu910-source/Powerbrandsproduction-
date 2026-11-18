<?php

namespace App\Models;

class TrainingRecord extends RecordModel
{
    public const CATEGORIES = ['rwt' => 'Responsible worker training (RWT)', 'task' => 'Task training', 'gmp' => 'GMP', 'safety' => 'Safety / PPE', 'other' => 'Other'];

    protected $casts = ['completed_on' => 'date', 'expires_on' => 'date'];
    public function person() { return $this->belongsTo(Person::class); }
    public function label(): string { return $this->topic; }
}
