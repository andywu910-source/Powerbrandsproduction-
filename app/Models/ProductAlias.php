<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

class ProductAlias extends Model
{
    use NoDelete;
    protected $guarded = ['id'];
    public function product() { return $this->belongsTo(Product::class); }
}
