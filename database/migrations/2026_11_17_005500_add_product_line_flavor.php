<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: optional hand-set "Product line" and "Flavor" for the Start-a-batch pickers
 * (Brand › Product line › Flavor). Empty = worked out from the product name (App\Support\ProductLine).
 * No existing data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('product_line', 120)->nullable()->after('product_type');
            $t->string('flavor', 120)->nullable()->after('product_line');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn(['product_line', 'flavor']);
        });
    }
};
