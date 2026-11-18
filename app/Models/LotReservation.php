<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

class LotReservation extends Model
{
    use NoDelete;
    protected $guarded = ['id'];
    protected $casts = ['qty' => 'float'];
    public function lot() { return $this->belongsTo(MaterialLot::class, 'material_lot_id'); }
}
