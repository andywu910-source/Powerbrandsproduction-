<?php

namespace App\Models;

class Person extends RecordModel
{
    protected $table = 'people';
    protected $casts = ['no_documentation' => 'boolean', 'active' => 'boolean'];

    public function user() { return $this->belongsTo(User::class); }
    public function trainingRecords() { return $this->hasMany(TrainingRecord::class)->whereNull('voided_at'); }

    /** Categories this person has current (not expired) training in. */
    public function currentTrainingCategories(): array
    {
        $today = now()->toDateString();

        return $this->trainingRecords
            ->filter(fn ($r) => $r->expires_on === null || $r->expires_on->toDateString() >= $today)
            ->pluck('category')->unique()->values()->all();
    }

    public function missingTraining(): array
    {
        $need = array_map('strtolower', array_map('trim', (array) Setting::get('training_required_categories', ['rwt', 'task'])));

        return array_values(array_diff($need, $this->currentTrainingCategories()));
    }

    public function label(): string { return $this->full_name; }
}
