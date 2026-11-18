<?php

namespace App\Models;

class Brand extends RecordModel
{
    protected $attributes = ['requires_barcode' => false, 'label_per_case_text' => 'Count per case box'];

    protected $casts = ['needs_review' => 'boolean', 'active' => 'boolean', 'requires_barcode' => 'boolean'];
    public function products() { return $this->hasMany(Product::class); }
    public function label(): string { return $this->name.($this->short_code ? " ({$this->short_code})" : ''); }
}
