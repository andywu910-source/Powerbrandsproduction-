<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

class ChecklistTemplateVersion extends Model
{
    use Audited, NoDelete;
    protected $guarded = ['id'];
    public function template() { return $this->belongsTo(ChecklistTemplate::class, 'template_id'); }
    public function items() { return $this->hasMany(ChecklistTemplateItem::class, 'version_id')->orderBy('item_no'); }
    public function requiresVerifier(): bool { return $this->verifier_role !== null && $this->verifier_role !== ''; }
}
