<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

/** Print log: one row per label print job, with the exact values printed. Append-only. */
class LabelPrint extends Model
{
    use NoDelete;

    protected $guarded = ['id'];
    protected $casts = ['fields' => 'array', 'partial' => 'boolean', 'layout' => 'array', 'layout_edited' => 'boolean'];

    public function typeLabel(): string
    {
        return (\App\Services\LabelService::TYPES[$this->label_type] ?? 'Master box').($this->partial ? ' (partial)' : '');
    }

    public function batch() { return $this->belongsTo(Batch::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function printer() { return $this->belongsTo(User::class, 'printed_by'); }
    public function label(): string { return $this->public_id; }
}
