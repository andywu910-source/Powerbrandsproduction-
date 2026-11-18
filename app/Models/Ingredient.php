<?php

namespace App\Models;

class Ingredient extends RecordModel
{
    public const KINDS = [
        'cannabis_active' => 'Cannabis / distillate (active)',
        'terpene' => 'Terpenes / flavor',
        'carrier' => 'Carrier / other liquid',
        'hardware' => 'Hardware (carts, cones, tubes)',
        'packaging' => 'Packaging',
        'label' => 'Labels',
        'other' => 'Other',
    ];

    protected $casts = ['is_cannabis' => 'boolean', 'requires_coa' => 'boolean', 'requires_attestation' => 'boolean', 'inhaled_allowed' => 'boolean', 'active' => 'boolean'];
    public function label(): string { return $this->name; }
}
