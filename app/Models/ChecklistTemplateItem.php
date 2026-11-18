<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

class ChecklistTemplateItem extends Model
{
    use NoDelete;
    public const TYPES = ['pass_fail' => 'Pass / Fail', 'yes_no' => 'Yes / No', 'numeric' => 'Reading (number)', 'text' => 'Text'];
    protected $guarded = ['id'];
    protected $casts = ['required' => 'boolean', 'min' => 'float', 'max' => 'float'];
}
