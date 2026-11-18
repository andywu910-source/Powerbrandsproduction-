<?php

namespace App\Models;

class Product extends RecordModel
{
    /** Product families from Andy's Production Log; each has its own process template. */
    public const FAMILIES = [
        'preroll' => 'Prerolls',
        'vape' => 'Vapes',
        'flower' => 'Flower',
        'rosin' => 'Rosin',
        'green_mantis' => 'Green Mantis (tinctures & topicals)',
    ];

    protected $attributes = ['case_pack_qty' => 10, 'serving_type' => 'single'];

    protected $casts = ['needs_review' => 'boolean', 'active' => 'boolean', 'unit_net_qty' => 'float', 'case_pack_qty' => 'integer'];

    public function brand() { return $this->belongsTo(Brand::class); }
    public function aliases() { return $this->hasMany(ProductAlias::class); }
    public function mmps() { return $this->hasMany(Mmp::class)->orderByDesc('version'); }

    public function currentMmp(): ?Mmp
    {
        return Mmp::where('product_id', $this->id)->where('status', 'approved')->orderByDesc('version')->first();
    }

    public function familyLabel(): string { return self::FAMILIES[$this->family] ?? 'Not set'; }
    public function label(): string { return $this->name; }
}
