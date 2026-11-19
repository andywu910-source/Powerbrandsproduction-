<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

/** One Compliance hub log (e.g. 2-E Tag Replacement): its group, frequency and entry-form fields. */
class LogDefinition extends Model
{
    use Audited, NoDelete;

    protected $guarded = ['id'];

    protected $casts = ['fields' => 'array', 'manager_only' => 'boolean', 'confirmed' => 'boolean', 'partial' => 'boolean', 'active' => 'boolean'];

    public function entries() { return $this->hasMany(LogEntry::class); }

    public function getRouteKeyName(): string { return 'slug'; }

    public function groupLabel(): string { return config('pbp_logs.groups.'.$this->group_key, $this->group_key); }

    public function frequencyLabel(): string { return config('pbp_logs.frequencies.'.$this->frequency, $this->frequency); }

    public function findingLabel(): string { return $this->finding === null ? 'No OCM code' : ($this->finding === 'T-1' ? 'T-1' : 'Finding '.$this->finding); }

    public function visibleTo(?User $u): bool
    {
        return $u !== null && $this->active && (! $this->manager_only || $u->isSupervisorOrAdmin());
    }

    /** Fields shown as columns in the recent-entries table (the first few, skipping long text / files). */
    public function tableFields(int $max = 6): array
    {
        return array_slice(array_values(array_filter($this->fields, fn ($f) => empty($f['removed']) && ! in_array($f['type'], ['textarea', 'file', 'bool'], true))), 0, $max);
    }
}
