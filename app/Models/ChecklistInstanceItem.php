<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

class ChecklistInstanceItem extends Model
{
    use Audited, NoDelete;
    protected $guarded = ['id'];
    protected $casts = ['within_limit' => 'boolean', 'answered_at' => 'datetime'];
    public array $correctable = ['response', 'note'];

    public function instance() { return $this->belongsTo(ChecklistInstance::class, 'instance_id'); }
    public function templateItem() { return $this->belongsTo(ChecklistTemplateItem::class, 'template_item_id'); }
    public function deviation() { return $this->belongsTo(Deviation::class); }
    public function answeredBy() { return $this->belongsTo(User::class, 'answered_by'); }
    public function revisions() { return $this->morphMany(Revision::class, 'record', 'record_type', 'record_id')->orderBy('id'); }

    public function isFailed(): bool { return $this->within_limit === false; }
    public function label(): string { return 'Checklist item #'.$this->id; }
}
