<?php

namespace App\Models;

class ChecklistTemplate extends RecordModel
{
    public const FREQUENCIES = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'on_demand' => 'On demand / per batch'];
    public const CATEGORIES = ['cleaning' => 'Cleaning & sanitation', 'equipment' => 'Equipment', 'security' => 'Security', 'pest' => 'Pest control', 'audit' => 'Internal audit', 'environment' => 'Environment', 'signage' => 'Signage'];
    public const ROLES = ['operator' => 'Any trained staff', 'supervisor' => 'Supervisor or admin', 'qc' => 'QC reviewer'];

    protected $casts = ['active' => 'boolean'];

    public function versions() { return $this->hasMany(ChecklistTemplateVersion::class, 'template_id')->orderBy('version_no'); }
    public function currentVersion() { return $this->belongsTo(ChecklistTemplateVersion::class, 'current_version_id'); }
    public function instances() { return $this->hasMany(ChecklistInstance::class, 'template_id'); }
    public function label(): string { return $this->name; }
}
