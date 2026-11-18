<?php

namespace App\Models;

/** Manufacturing stages for one product family (Production step 6). Admin-editable. */
class ProcessTemplate extends RecordModel
{
    protected $casts = ['active' => 'boolean'];

    public function stages() { return $this->hasMany(ProcessTemplateStage::class)->orderBy('stage_no'); }
    public function activeStages() { return $this->stages()->where('active', true); }

    public static function forFamily(?string $family): ?self
    {
        return $family ? static::where('family', $family)->where('active', true)->first() : null;
    }

    public function label(): string { return $this->name; }
}
