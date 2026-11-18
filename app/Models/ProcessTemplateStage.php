<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

class ProcessTemplateStage extends Model
{
    use Audited, NoDelete;
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean', 'default_steps' => 'array'];
    public function template() { return $this->belongsTo(ProcessTemplate::class, 'process_template_id'); }
}
