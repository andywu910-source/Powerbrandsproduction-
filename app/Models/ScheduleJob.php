<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

class ScheduleJob extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected $casts = ['removed_at' => 'datetime'];

    public function cell() { return $this->belongsTo(ScheduleCell::class, 'cell_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function batch() { return $this->belongsTo(Batch::class); }

    public function title(): string
    {
        $t = $this->product ? trim(($this->product->brand?->name ? $this->product->brand->name.' ' : '').$this->product->name) : ($this->note ?: 'Job');

        return $t;
    }
}
