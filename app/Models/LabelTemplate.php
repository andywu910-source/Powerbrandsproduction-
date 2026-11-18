<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

/** "Save as template for this product": newest row per product + label type is used. Append-only. */
class LabelTemplate extends Model
{
    use NoDelete;

    protected $guarded = ['id'];
    protected $casts = ['layout' => 'array'];

    public function product() { return $this->belongsTo(Product::class); }
    public function saver() { return $this->belongsTo(User::class, 'saved_by'); }

    public static function latestFor(?int $productId, string $type): ?self
    {
        return $productId ? static::where('product_id', $productId)->where('label_type', $type)->orderByDesc('id')->first() : null;
    }
}
