<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OCM sampling / retention (additive only):
 *  - products.serving_type: single (default) or multi (edibles / capsules only), picks the Table 1 column.
 *  - batches.expiration_date: optional; retention samples are kept until 30 days after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'serving_type')) {
            Schema::table('products', fn (Blueprint $t) => $t->string('serving_type', 10)->default('single'));
        }
        if (! Schema::hasColumn('batches', 'expiration_date')) {
            Schema::table('batches', fn (Blueprint $t) => $t->date('expiration_date')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('serving_type'));
        Schema::table('batches', fn (Blueprint $t) => $t->dropColumn('expiration_date'));
    }
};
